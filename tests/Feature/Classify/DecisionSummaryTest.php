<?php

namespace Tests\Feature\Classify;

use App\Models\ActivityLog;
use App\Models\ClassificationItem;
use App\Models\User;
use App\Services\Classify\DecisionSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('classify.vector.membership_k', 3);
        config()->set('classify.search_resolver.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $results
     */
    private function item(array $attrs, array $results = []): ClassificationItem
    {
        $item = ClassificationItem::create($attrs + [
            'batch' => 'b', 'source_text' => 'item '.bin2hex(random_bytes(4)), 'source_hash' => bin2hex(random_bytes(32)),
        ]);
        foreach ($results as $r) {
            $item->results()->create($r + ['status' => 'needs_review']);
        }

        return $item;
    }

    /** @return array{method: string, reason: string} */
    private function summarize(ClassificationItem $item): array
    {
        return app(DecisionSummary::class)->forItems(collect([$item->fresh()]))[$item->id];
    }

    public function test_memory_names_where_the_verified_answer_came_from(): void
    {
        // Older cache rows carry the source only in the explanation; newer ones in the trace.
        $consensus = $this->item(['resolution' => 'agreed', 'final_code' => '1101'], [
            ['mechanism' => 'cache', 'matched_code' => '1101', 'status' => 'auto_confirmed', 'explanation' => 'Verified answer from the cache (auto:consensus).'],
        ]);
        $human = $this->item(['resolution' => 'agreed', 'final_code' => '3401'], [
            ['mechanism' => 'cache', 'matched_code' => '3401', 'status' => 'auto_confirmed', 'explanation' => 'Verified answer from the cache (confirmed).', 'trace' => ['source' => 'confirmed']],
        ]);
        $gold = $this->item(['resolution' => 'agreed', 'final_code' => '3004'], [
            ['mechanism' => 'cache', 'matched_code' => '3004', 'status' => 'auto_confirmed', 'explanation' => 'Verified answer from the cache (fedor).'],
        ]);

        $this->assertSame(['method' => 'memory', 'reason' => 'The name matched a verified answer in memory (source: an earlier unanimous AI decision).'], $this->summarize($consensus));
        $this->assertStringContainsString('(source: a human confirmation)', $this->summarize($human)['reason']);
        $this->assertStringContainsString('(source: reference labelling)', $this->summarize($gold)['reason']);
    }

    public function test_trash_gives_the_rule_that_tripped(): void
    {
        $item = $this->item(['resolution' => 'trash'], [
            ['mechanism' => 'trash', 'status' => 'trash', 'trace' => ['rule' => 'person']],
        ]);

        $this->assertSame(['method' => 'trash', 'reason' => 'Only a person\'s name — no product is named.'], $this->summarize($item));
    }

    public function test_consensus_states_the_agreement_not_directs_own_text(): void
    {
        // The fine-tuned Direct writes a labelling note, not a reason — it must never surface.
        $item = $this->item(['resolution' => 'agreed', 'final_code' => '2201', 'kind' => 'good'], [
            ['mechanism' => 'direct', 'matched_code' => '2201', 'kind' => 'good', 'status' => 'auto_confirmed', 'explanation' => 'q122 + blind GPT confirm 2026-07-18'],
            ['mechanism' => 'vector', 'matched_code' => '2853000000', 'kind' => 'good', 'candidates' => [
                ['code' => '2853000000', 'kind' => 'good'],
                ['code' => '2201100000', 'kind' => 'good'],
                ['code' => '2202100000', 'kind' => 'good'],
            ]],
        ]);

        $summary = $this->summarize($item);

        $this->assertSame('consensus', $summary['method']);
        $this->assertSame('Two independent methods agree: the AI model (Direct) chose 2201, and the catalog vector search ranks it #2 of 3.', $summary['reason']);
        $this->assertStringNotContainsString('q122', $summary['reason']);
    }

    public function test_ensemble_counts_the_votes_and_quotes_its_understanding(): void
    {
        $item = $this->item(['resolution' => 'ai_resolved', 'final_code' => '2201'], [
            ['mechanism' => 'ensemble', 'matched_code' => '2201', 'status' => 'auto_confirmed', 'trace' => [
                'agreement' => 'unanimous', 'picks' => ['2201', '2201', 'ERR'],
                'understanding' => ['identity' => 'bottled mineral water'],
            ]],
        ]);

        $this->assertSame([
            'method' => 'ensemble',
            'reason' => 'The methods diverged; a repeat vote over the catalog shortlist chose 2201 (2 of 3 votes). Understood as “bottled mineral water”.',
        ], $this->summarize($item));
    }

    public function test_web_search_quotes_the_model_and_lists_its_sources(): void
    {
        $item = $this->item(['resolution' => 'ai_resolved', 'final_code' => '0207'], [
            ['mechanism' => 'ensemble', 'status' => 'needs_review', 'trace' => ['agreement' => 'split', 'picks' => ['0207', '0210', '1602']]],
            ['mechanism' => 'search', 'matched_code' => '0207', 'status' => 'auto_confirmed', 'confidence' => 0.95,
                'explanation' => "Fresh chicken fillet ([bazarstore.az](https://bazarstore.az/p/1)),\n heading 0207. [web: bazarstore.az, arazmarket.az]"],
        ]);

        $this->assertSame([
            'method' => 'web_search',
            'reason' => 'The methods diverged; a web search identified the item (confidence 95%). Model: “Fresh chicken fillet (bazarstore.az), heading 0207.” Sources: bazarstore.az, arazmarket.az.',
        ], $this->summarize($item));
    }

    public function test_an_answer_no_trace_row_backs_is_not_pinned_on_a_step(): void
    {
        // A re-run overwrote the search trace after the item was settled at another code.
        $item = $this->item(['resolution' => 'ai_resolved', 'final_code' => '5512'], [
            ['mechanism' => 'search', 'matched_code' => '5407', 'status' => 'needs_review', 'confidence' => 0.3, 'explanation' => 'A textile fabric.'],
        ]);

        $this->assertSame('ai', $this->summarize($item)['method']);
    }

    public function test_human_decisions_name_the_reviewer_and_a_correction(): void
    {
        $user = User::factory()->create(['name' => 'Aysel']);
        $at = now()->setDate(2026, 9, 12);

        $confirmed = $this->item(['resolution' => 'confirmed', 'final_code' => '3004', 'confirmed_by' => $user->id, 'confirmed_at' => $at]);
        $corrected = $this->item(['resolution' => 'confirmed', 'final_code' => '3003', 'confirmed_by' => $user->id, 'confirmed_at' => $at]);
        $this->action($corrected, $user, 'classification.corrected', ['code' => '3003', 'was' => '3004']);
        $rejected = $this->item(['resolution' => 'rejected']);
        $this->action($rejected, $user, 'classification.reject');
        $bulkRejected = $this->item(['resolution' => 'rejected']); // a bulk reject leaves no per-item action

        $this->assertSame(['method' => 'human', 'reason' => 'Confirmed by Aysel on 2026-09-12.'], $this->summarize($confirmed));
        $this->assertSame('Corrected by Aysel on 2026-09-12 (was 3004).', $this->summarize($corrected)['reason']);
        $this->assertSame('Rejected by Aysel on '.now()->format('Y-m-d').'.', $this->summarize($rejected)['reason']);
        $this->assertSame('Rejected by a reviewer.', $this->summarize($bulkRejected)['reason']);
    }

    public function test_open_items_say_why_a_human_is_needed(): void
    {
        $unavailable = $this->item(['resolution' => 'conflict'], [
            ['mechanism' => 'search', 'status' => 'no_match', 'explanation' => 'Search resolver unavailable.'],
        ]);
        $unsure = $this->item(['resolution' => 'conflict'], [
            ['mechanism' => 'search', 'matched_code' => '3924', 'status' => 'needs_review', 'confidence' => 0.6, 'explanation' => 'A plastic lunch container.'],
        ]);
        $none = $this->item(['resolution' => 'no_match']);

        $this->assertSame([
            'method' => 'needs_human',
            'reason' => 'The methods diverged and the web search was unavailable — a human needs to decide.',
        ], $this->summarize($unavailable));
        $this->assertSame(
            'The methods diverged; the web search suggests 3924 but is not confident enough (60%) — a human needs to decide. Model: “A plastic lunch container.”',
            $this->summarize($unsure)['reason'],
        );
        $this->assertSame('No method found a code — a human needs to decide.', $this->summarize($none)['reason']);
    }

    public function test_items_still_in_the_pipeline_are_in_progress(): void
    {
        $pending = $this->item(['resolution' => 'pending']);
        // A conflict with no search row yet: the resolver is still working on it…
        $resolving = $this->item(['resolution' => 'conflict'], [['mechanism' => 'direct', 'matched_code' => '8471']]);

        $this->assertSame('in_progress', $this->summarize($pending)['method']);
        $this->assertSame('in_progress', $this->summarize($resolving)['method']);

        // …unless the resolver is off — then the conflict is terminal.
        config()->set('classify.search_resolver.enabled', false);
        $this->assertSame('needs_human', $this->summarize($resolving)['method']);
    }

    public function test_labels_and_reasons_follow_the_ui_language(): void
    {
        app()->setLocale('ru');
        $item = $this->item(['resolution' => 'agreed', 'final_code' => '1101'], [
            ['mechanism' => 'cache', 'matched_code' => '1101', 'status' => 'auto_confirmed', 'trace' => ['source' => 'auto:consensus']],
        ]);

        $this->assertSame('ИИ: Direct + вектор', DecisionSummary::label('consensus'));
        $this->assertSame('Название совпало с проверенным ответом из памяти (источник: прежнее единогласное решение ИИ).', $this->summarize($item)['reason']);
    }

    /** @param  array<string, mixed>  $properties */
    private function action(ClassificationItem $item, User $user, string $action, array $properties = []): void
    {
        ActivityLog::create([
            'user_id' => $user->id, 'action' => $action,
            'subject_type' => $item->getMorphClass(), 'subject_id' => $item->id,
            'properties' => ['id' => $item->id] + $properties, 'created_at' => now(),
        ]);
    }
}
