<?php

namespace Tests\Feature\Classify;

use App\Models\ClassificationItem;
use App\Services\Classify\Consensus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Consensus::finalize() integration: an item auto-resolves only once every authoritative
// mechanism has reported AND the auto-resolve rule holds — broker == direct and the vector
// top-K corroborates (see ConsensusMajorityTest for the pure resolve() rule matrix).
class ConsensusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('classify.mechanisms.enabled', ['vector', 'broker', 'direct']);
        config()->set('classify.mechanisms.shadow', []);
    }

    /** @param array<int, string> $vectorCandidates */
    private function seedTriad(ClassificationItem $item, ?string $broker, ?string $direct, array $vectorCandidates): void
    {
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => $broker, 'status' => 'auto_confirmed', 'catalog_id' => null, 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'direct', 'matched_code' => $direct, 'status' => 'auto_confirmed', 'catalog_id' => null, 'kind' => 'good']);
        $item->results()->create([
            'mechanism' => 'vector',
            'matched_code' => $vectorCandidates[0] ?? null,
            'status' => 'auto_confirmed', 'catalog_id' => null, 'kind' => 'good',
            'candidates' => array_map(fn ($c) => ['code' => $c, 'kind' => 'good'], $vectorCandidates),
        ]);
    }

    public function test_finalize_agrees_when_broker_direct_match_and_vector_corroborates(): void
    {
        $item = $this->item();
        $this->seedTriad($item, '9018390000', '9018110000', ['6215200000', '9018900000']);

        (new Consensus)->finalize($item);

        $this->assertSame('agreed', $item->fresh()->resolution);
        $this->assertSame('9018', $item->fresh()->final_code);
    }

    public function test_finalize_conflicts_when_vector_does_not_corroborate(): void
    {
        $item = $this->item();
        $this->seedTriad($item, '9018390000', '9018110000', ['6215200000', '3004900000']);

        (new Consensus)->finalize($item);

        $this->assertSame('conflict', $item->fresh()->resolution);
    }

    public function test_finalize_stays_pending_until_all_mechanisms_report(): void
    {
        $item = $this->item();
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '9018390000', 'status' => 'auto_confirmed', 'catalog_id' => null, 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'direct', 'matched_code' => '9018110000', 'status' => 'auto_confirmed', 'catalog_id' => null, 'kind' => 'good']);
        // vector has not reported yet.

        (new Consensus)->finalize($item);

        $this->assertSame('pending', $item->fresh()->resolution);
    }

    public function test_shadow_mechanism_does_not_block_the_authoritative_decision(): void
    {
        // A shadowed extra mechanism runs and is stored but is excluded from the
        // authoritative set, so it neither blocks reporting nor changes the outcome.
        config()->set('classify.mechanisms.enabled', ['vector', 'broker', 'direct', 'extra']);
        config()->set('classify.mechanisms.shadow', ['extra']);

        $item = $this->item();
        $this->seedTriad($item, '9018390000', '9018110000', ['9018900000']);
        $item->results()->create(['mechanism' => 'extra', 'matched_code' => '6215200000', 'status' => 'auto_confirmed', 'catalog_id' => null, 'kind' => 'good']);

        (new Consensus)->finalize($item);

        $this->assertSame('agreed', $item->fresh()->resolution);
        $this->assertSame('9018', $item->fresh()->final_code);
    }

    public function test_finalize_never_overwrites_a_human_decision(): void
    {
        $item = $this->item('confirmed');
        $item->update(['final_code' => 'HUMAN']);
        $this->seedTriad($item, '9018390000', '9018110000', ['9018900000']);

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
