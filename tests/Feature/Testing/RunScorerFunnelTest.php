<?php

namespace Tests\Feature\Testing;

use App\Models\ClassificationItem;
use App\Models\TestDataset;
use App\Models\TestDatasetRow;
use App\Models\TestRun;
use App\Services\Testing\RunScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RunScorer::score()'s funnel breakdown: bucketing by pre-vote agreement tier
 * (unanimous/majority/divergent), excluding no-evidence rows from those buckets, and
 * the "promoted" counts reflecting the REAL memory_promotion/grounded_search gates
 * (AnswerCacheService::wouldPromote()/wouldPromoteGroundedSearch()) rather than a
 * hypothetical one — both paths fire for real during a test run (scoped to the
 * dataset's own memory via TestRunFinalizer / SearchResolverService), exactly like
 * prod fires them scoped to production.
 */
class RunScorerFunnelTest extends TestCase
{
    use RefreshDatabase;

    public function test_funnel_buckets_by_agreement_and_excludes_no_match_with_gates_off(): void
    {
        // Explicit, not assumed: local .env may default these on for dev.
        config()->set('classify.memory_promotion.enabled', false);
        config()->set('classify.memory_promotion.grounded_search.enabled', false);

        $run = $this->buildRun();

        $funnel = app(RunScorer::class)->score($run)['funnel'];

        $this->assertSame(3, $funnel['total']);

        // Row 1: unanimous (3/3), correct.
        $this->assertSame(1, $funnel['prevote'][3]['ran']);
        $this->assertSame(1, $funnel['prevote'][3]['correct']);
        $this->assertSame(0, $funnel['prevote'][3]['promoted']); // gate off

        // Rows 2 & 3: bare majority (2/3) — both tally into prevote[2].
        $this->assertSame(2, $funnel['prevote'][2]['ran']);
        // The plurality pick (vector+broker's own 0402), not the search answer, is what
        // gets scored here — it happens to match the expected heading for both rows.
        $this->assertSame(2, $funnel['prevote'][2]['correct']);

        // Row 4: full divergence (1/3) — every mechanism proposes a different heading, so
        // the "winning" pick is an arbitrary 1-of-3 (1111), which does NOT match the
        // expected 4444 (only the ungrounded search answer, scored separately below, does).
        $this->assertSame(1, $funnel['prevote'][1]['ran']);
        $this->assertSame(0, $funnel['prevote'][1]['correct']);

        // Row 5 (no_match, count === 0) contributes to NO prevote bucket at all.
        $totalBucketed = $funnel['prevote'][1]['ran'] + $funnel['prevote'][2]['ran'] + $funnel['prevote'][3]['ran'];
        $this->assertSame(4, $totalBucketed); // 5 rows minus the 1 no_match row

        // search_by_origin: row 2 (confident+grounded) and row 3 (unconfident) both
        // land in bucket 2; row 4 (confident but ungrounded) lands in bucket 1. None
        // promoted — memory_promotion / grounded_search are both off by default.
        $this->assertSame(2, $funnel['search_by_origin'][2]['ran']);
        $this->assertSame(0, $funnel['search_by_origin'][2]['promoted']);
        $this->assertSame(1, $funnel['search_by_origin'][1]['ran']);
        $this->assertSame(0, $funnel['search_by_origin'][1]['promoted']);
    }

    public function test_funnel_promoted_counts_reflect_the_real_gates_when_enabled(): void
    {
        config()->set('classify.memory_promotion.enabled', true);
        config()->set('classify.memory_promotion.shadow', false);
        config()->set('classify.memory_promotion.grounded_search.enabled', true);

        $run = $this->buildRun();

        $funnel = app(RunScorer::class)->score($run)['funnel'];

        // Row 1: unanimous + gates on → wouldPromote() passes (and, for real, would land
        // in this dataset's own memory scope via TestRunFinalizer → promote()).
        $this->assertSame(1, $funnel['prevote'][3]['promoted']);

        // Row 2: confident (0.95 >= 0.90) AND grounded → promoted. Row 3 in the same
        // bucket is unconfident (0.7) → not promoted. Bucket total is exactly 1.
        $this->assertSame(1, $funnel['search_by_origin'][2]['promoted']);

        // Row 4: confident (0.99) but UNGROUNDED (doesn't overlap any original
        // vector/broker/direct candidate) → never promoted, gate or no gate.
        $this->assertSame(0, $funnel['search_by_origin'][1]['promoted']);

        // The funnel's total "sent to memory" — what the Итог row sums — is exactly
        // the unanimous bucket's promotions plus every search-by-origin promotion.
        $totalPromoted = $funnel['prevote'][3]['promoted']
            + $funnel['search_by_origin'][1]['promoted']
            + $funnel['search_by_origin'][2]['promoted'];
        $this->assertSame(2, $totalPromoted);
    }

    private function buildRun(): TestRun
    {
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => []]);
        $run = TestRun::create([
            'test_dataset_id' => $dataset->id, 'description' => 'r',
            'mechanisms' => ['enabled' => ['vector', 'broker', 'direct'], 'shadow' => []],
            'config' => [], 'status' => 'running', 'total' => 5,
        ]);
        $run->update(['batch' => TestRun::batchKey($run->id)]);

        // Row 1: unanimous 3/3, correct.
        $this->row($dataset, $run, '0901', [
            'vector' => '0901110000', 'broker' => '0901220000', 'direct' => '0901330000',
        ]);

        // Row 2: bare majority (vector+broker agree on 0402, direct dissents), search
        // is confident (0.95) AND grounded (0402 overlaps vector/broker's own pick).
        $this->row($dataset, $run, '0402', [
            'vector' => '0402100000', 'broker' => '0402200000', 'direct' => '0500000000',
        ], search: ['code' => '0402550000', 'confidence' => 0.95]);

        // Row 3: same 2/3 split, but the search answer is UNDER-confident (0.7).
        $this->row($dataset, $run, '0402', [
            'vector' => '0402100000', 'broker' => '0402200000', 'direct' => '0500000000',
        ], search: ['code' => '0402999999', 'confidence' => 0.7]);

        // Row 4: full divergence (1/3 each), search is confident but its heading (4444)
        // overlaps NONE of the original 1111/2222/3333 candidates — ungrounded.
        $this->row($dataset, $run, '4444', [
            'vector' => '1111110000', 'broker' => '2222220000', 'direct' => '3333330000',
        ], search: ['code' => '4444440000', 'confidence' => 0.99]);

        // Row 5: no mechanism produced any code at all — count === 0, excluded from
        // the prevote breakdown entirely (not folded into the weakest bucket).
        $this->row($dataset, $run, '0000', [
            'vector' => null, 'broker' => null, 'direct' => null,
        ]);

        return $run;
    }

    /** @param  array<string, ?string>  $mechResults  mechanism => matched_code (or null for no_match) */
    private function row(TestDataset $dataset, TestRun $run, string $expectedHeading, array $mechResults, ?array $search = null): TestDatasetRow
    {
        $row = $dataset->rows()->create([
            'source_text' => bin2hex(random_bytes(8)),
            'expected_heading' => $expectedHeading,
            'expected_is_service' => false,
        ]);

        $item = ClassificationItem::create([
            'batch' => $run->batch, 'test_run_id' => $run->id, 'test_dataset_row_id' => $row->id,
            'source_text' => $row->source_text, 'source_hash' => bin2hex(random_bytes(32)),
            // The 4-digit heading, as Consensus::resolve() would actually store it — NOT a
            // raw per-mechanism full code. wouldPromote()'s eligibility check requires this
            // to match /^\d{4}$/.
            'resolution' => 'agreed', 'final_code' => $expectedHeading, 'kind' => 'good',
        ]);

        foreach ($mechResults as $mechanism => $code) {
            $item->results()->create([
                'mechanism' => $mechanism, 'matched_code' => $code,
                'kind' => $code !== null ? 'good' : null,
                'status' => $code !== null ? 'needs_review' : 'no_match',
            ]);
        }

        if ($search !== null) {
            $item->results()->create([
                'mechanism' => 'search', 'matched_code' => $search['code'], 'kind' => 'good',
                'status' => 'auto_confirmed', 'confidence' => $search['confidence'],
            ]);
        }

        return $row;
    }
}
