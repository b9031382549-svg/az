<?php

namespace Tests\Feature\Classify;

use App\Jobs\ClassifyMechanismJob;
use App\Models\ClassificationItem;
use App\Services\Classify\Consensus;
use App\Services\Classify\Mechanisms\ClassifierMechanism;
use App\Services\Classify\Mechanisms\MechanismRegistry;
use App\Services\Classify\Mechanisms\MechanismResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ClassifyMechanismJobTest extends TestCase
{
    use RefreshDatabase;

    private function registryWith(string $key, MechanismResult $result): MechanismRegistry
    {
        $registry = new MechanismRegistry;
        $registry->register(new class($key, $result) implements ClassifierMechanism
        {
            public function __construct(private string $key, private MechanismResult $result) {}

            public function key(): string
            {
                return $this->key;
            }

            public function classify(string $text): MechanismResult
            {
                return $this->result;
            }
        });

        return $registry;
    }

    public function test_job_writes_result_and_finalizes_consensus(): void
    {
        config()->set('classify.mechanisms.enabled', ['fake']);
        $registry = $this->registryWith('fake', new MechanismResult(
            matchedCode: '8471300000', catalogId: null, kind: 'good',
            confidence: 0.9, status: 'auto_confirmed',
        ));
        $item = $this->item();

        (new ClassifyMechanismJob($item->id, 'fake'))->handle($registry, new Consensus);

        $this->assertDatabaseHas('classification_results', [
            'classification_item_id' => $item->id, 'mechanism' => 'fake', 'matched_code' => '8471300000',
        ]);
        $this->assertSame('agreed', $item->fresh()->resolution);
        $this->assertSame('8471300000', $item->fresh()->final_code);
    }

    public function test_failed_records_abstaining_error_and_unblocks(): void
    {
        config()->set('classify.mechanisms.enabled', ['fake']);
        $item = $this->item();

        (new ClassifyMechanismJob($item->id, 'fake'))->failed(new RuntimeException('boom'));

        $this->assertDatabaseHas('classification_results', [
            'classification_item_id' => $item->id, 'mechanism' => 'fake', 'status' => 'error',
        ]);
        $this->assertSame('no_match', $item->fresh()->resolution);
    }

    public function test_job_is_idempotent_on_retry(): void
    {
        config()->set('classify.mechanisms.enabled', ['fake']);
        $registry = $this->registryWith('fake', new MechanismResult(
            matchedCode: 'C1', catalogId: null, kind: 'good', confidence: 0.9, status: 'auto_confirmed',
        ));
        $item = $this->item();

        (new ClassifyMechanismJob($item->id, 'fake'))->handle($registry, new Consensus);
        (new ClassifyMechanismJob($item->id, 'fake'))->handle($registry, new Consensus);

        $this->assertSame(1, $item->results()->count());
    }

    private function item(): ClassificationItem
    {
        return ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'noutbuk',
            'source_hash' => bin2hex(random_bytes(32)), 'resolution' => 'pending',
        ]);
    }
}
