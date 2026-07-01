<?php

namespace Tests\Feature\Classify;

use App\Models\ClassificationItem;
use App\Models\ClassificationResult;
use App\Services\Classify\Consensus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The consensus policy: mechanisms auto-resolve only on full agreement; any
// divergence (differing codes, or a partial answer) goes to a human.
class ConsensusTest extends TestCase
{
    use RefreshDatabase;

    private function makeResult(?string $code, string $status = 'auto_confirmed', ?int $catalogId = 1): ClassificationResult
    {
        return new ClassificationResult([
            'mechanism' => 'm', 'matched_code' => $code, 'status' => $status,
            'catalog_id' => $catalogId, 'kind' => 'good',
        ]);
    }

    public function test_agreed_when_all_same_code_and_confident(): void
    {
        $d = (new Consensus)->resolve(collect([$this->makeResult('C1'), $this->makeResult('C1')]));

        $this->assertSame('agreed', $d['resolution']);
        $this->assertSame('C1', $d['final_code']);
        $this->assertSame(1, $d['final_catalog_id']);
    }

    public function test_review_when_agreed_code_but_not_all_confident(): void
    {
        $d = (new Consensus)->resolve(collect([
            $this->makeResult('C1', 'auto_confirmed'),
            $this->makeResult('C1', 'needs_review'),
        ]));

        $this->assertSame('review', $d['resolution']);
        $this->assertSame('C1', $d['final_code']);
    }

    public function test_conflict_when_codes_differ(): void
    {
        $d = (new Consensus)->resolve(collect([$this->makeResult('C1'), $this->makeResult('C2')]));

        $this->assertSame('conflict', $d['resolution']);
        $this->assertNull($d['final_code']);
    }

    public function test_conflict_when_one_mechanism_abstains(): void
    {
        $d = (new Consensus)->resolve(collect([$this->makeResult('C1'), $this->makeResult(null, 'no_match', null)]));

        $this->assertSame('conflict', $d['resolution']);
    }

    public function test_no_match_when_all_abstain(): void
    {
        $d = (new Consensus)->resolve(collect([
            $this->makeResult(null, 'no_match', null),
            $this->makeResult(null, 'error', null),
        ]));

        $this->assertSame('no_match', $d['resolution']);
    }

    public function test_finalize_stays_pending_until_all_mechanisms_report(): void
    {
        config()->set('classify.mechanisms.enabled', ['vector', 'broker']);
        $item = $this->item();
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => 'C1', 'status' => 'auto_confirmed', 'catalog_id' => null, 'kind' => 'good']);

        (new Consensus)->finalize($item);

        $this->assertSame('pending', $item->fresh()->resolution);
    }

    public function test_finalize_resolves_when_all_mechanisms_report(): void
    {
        config()->set('classify.mechanisms.enabled', ['vector', 'broker']);
        $item = $this->item();
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => 'C1', 'status' => 'auto_confirmed', 'catalog_id' => null, 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => 'C1', 'status' => 'auto_confirmed', 'catalog_id' => null, 'kind' => 'good']);

        (new Consensus)->finalize($item);

        $this->assertSame('agreed', $item->fresh()->resolution);
        $this->assertSame('C1', $item->fresh()->final_code);
    }

    public function test_finalize_never_overwrites_a_human_decision(): void
    {
        config()->set('classify.mechanisms.enabled', ['vector']);
        $item = $this->item('confirmed');
        $item->update(['final_code' => 'HUMAN']);
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => 'C1', 'status' => 'auto_confirmed', 'catalog_id' => null, 'kind' => 'good']);

        (new Consensus)->finalize($item);

        $this->assertSame('confirmed', $item->fresh()->resolution);
        $this->assertSame('HUMAN', $item->fresh()->final_code);
    }

    private function item(string $resolution = 'pending'): ClassificationItem
    {
        return ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'x',
            'source_hash' => bin2hex(random_bytes(32)), 'resolution' => $resolution,
        ]);
    }
}
