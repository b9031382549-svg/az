<?php

namespace Tests\Feature\Classify;

use App\Models\AnswerCache;
use App\Services\Classify\Mechanisms\DirectLlmMechanism;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportFinetuneExamplesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function row(string $name, ?string $heading, bool $isService = false, string $source = 'gold'): AnswerCache
    {
        return AnswerCache::create([
            'test_dataset_id' => 0, 'source' => $source, 'name' => $name,
            'name_key' => AnswerCache::keyFor($name), 'heading' => $heading, 'is_service' => $isService,
        ]);
    }

    private function export(array $options = []): array
    {
        $path = storage_path('app/finetune/test-'.bin2hex(random_bytes(4)).'.jsonl');
        $this->paths[] = $path;
        $this->artisan('finetune:export-examples', ['--output' => $path] + $options)->assertSuccessful();

        return collect(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ->map(fn ($l) => json_decode($l, true))
            ->all();
    }

    public function test_a_good_is_exported_in_the_exact_live_inference_chat_format(): void
    {
        $this->row('Cotton Bedsheet', '6302');

        $rows = $this->export();

        $this->assertCount(1, $rows);
        $messages = $rows[0]['messages'];
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame(DirectLlmMechanism::prompt('heading'), $messages[0]['content']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame('ITEM: Cotton Bedsheet', $messages[1]['content']);
        $this->assertSame('assistant', $messages[2]['role']);

        $answer = json_decode($messages[2]['content'], true);
        $this->assertSame('6302', $answer['heading']);
        $this->assertSame('good', $answer['kind']);
        $this->assertSame(1.0, $answer['confidence']);
        $this->assertSame('Cotton Bedsheet', $answer['reason']);
    }

    public function test_a_service_has_a_null_heading_and_service_kind(): void
    {
        $this->row('Hotel Booking', null, isService: true);

        $rows = $this->export();

        $answer = json_decode($rows[0]['messages'][2]['content'], true);
        $this->assertNull($answer['heading']);
        $this->assertSame('service', $answer['kind']);
    }

    public function test_only_production_scope_rows_are_exported(): void
    {
        $this->row('Prod Widget', '8471');
        AnswerCache::create(['test_dataset_id' => 5, 'source' => 'gold', 'name' => 'Dataset Widget',
            'name_key' => AnswerCache::keyFor('Dataset Widget'), 'heading' => '8471', 'is_service' => false]);

        $rows = $this->export();

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Prod Widget', $rows[0]['messages'][1]['content']);
    }

    public function test_dataset_option_exports_only_that_datasets_own_scope(): void
    {
        $this->row('Prod Widget', '8471'); // scope 0 — must NOT appear
        AnswerCache::create(['test_dataset_id' => 5, 'source' => 'gold', 'name' => 'Dataset 5 Widget',
            'name_key' => AnswerCache::keyFor('Dataset 5 Widget'), 'heading' => '8471', 'is_service' => false]);
        AnswerCache::create(['test_dataset_id' => 6, 'source' => 'gold', 'name' => 'Dataset 6 Widget',
            'name_key' => AnswerCache::keyFor('Dataset 6 Widget'), 'heading' => '8471', 'is_service' => false]);

        $rows = $this->export(['--dataset' => 5]);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Dataset 5 Widget', $rows[0]['messages'][1]['content']);
    }

    public function test_a_source_not_in_the_named_priority_list_still_exports_last(): void
    {
        AnswerCache::create(['test_dataset_id' => 5, 'source' => 'catalog_unified_2026-07-19', 'name' => 'Bulk Item',
            'name_key' => AnswerCache::keyFor('Bulk Item'), 'heading' => '8471', 'is_service' => false]);

        $rows = $this->export(['--dataset' => 5]);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Bulk Item', $rows[0]['messages'][1]['content']);
    }

    public function test_named_priority_sources_still_fill_before_an_unnamed_source(): void
    {
        AnswerCache::create(['test_dataset_id' => 5, 'source' => 'confirmed', 'name' => 'Confirmed Item',
            'name_key' => AnswerCache::keyFor('Confirmed Item'), 'heading' => '8471', 'is_service' => false]);
        for ($i = 0; $i < 5; $i++) {
            AnswerCache::create(['test_dataset_id' => 5, 'source' => 'catalog_unified_2026-07-19', 'name' => "Bulk {$i}",
                'name_key' => AnswerCache::keyFor("Bulk {$i}"), 'heading' => '8471', 'is_service' => false]);
        }

        $rows = $this->export(['--dataset' => 5, '--cap' => 1]);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Confirmed Item', $rows[0]['messages'][1]['content']);
    }

    public function test_a_heading_over_the_cap_is_trimmed(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->row("Widget {$i}", '8471');
        }

        $rows = $this->export(['--cap' => 3]);

        $this->assertCount(3, $rows);
    }

    public function test_a_heading_under_the_cap_is_kept_in_full(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->row("Widget {$i}", '8471');
        }

        $rows = $this->export(['--cap' => 200]);

        $this->assertCount(3, $rows);
    }

    public function test_trusted_sources_fill_before_gold_when_a_heading_exceeds_the_cap(): void
    {
        // 2 confirmed + 5 gold on the same heading, cap 3 -> both confirmed MUST survive,
        // gold only fills the single remaining slot.
        $this->row('Confirmed A', '8471', source: 'confirmed');
        $this->row('Confirmed B', '8471', source: 'confirmed');
        for ($i = 0; $i < 5; $i++) {
            $this->row("Gold {$i}", '8471', source: 'gold');
        }

        $rows = $this->export(['--cap' => 3]);

        $this->assertCount(3, $rows);
        $texts = collect($rows)->map(fn ($r) => $r['messages'][1]['content']);
        $this->assertTrue($texts->contains('ITEM: Confirmed A'));
        $this->assertTrue($texts->contains('ITEM: Confirmed B'));
        $goldCount = $texts->filter(fn ($t) => str_starts_with($t, 'ITEM: Gold '))->count();
        $this->assertSame(1, $goldCount);
    }

    public function test_priority_order_is_confirmed_then_auto_consensus_then_grounded_then_fedor_then_gold(): void
    {
        $this->row('Gold Item', '8471', source: 'gold');
        $this->row('Fedor Item', '8471', source: 'fedor');
        $this->row('Grounded Item', '8471', source: 'ai_resolved_grounded');
        $this->row('Consensus Item', '8471', source: 'auto:consensus');
        $this->row('Confirmed Item', '8471', source: 'confirmed');

        // Cap tight enough that only the top 2 priority tiers fit.
        $rows = $this->export(['--cap' => 2]);

        $texts = collect($rows)->map(fn ($r) => $r['messages'][1]['content'])->all();
        $this->assertContains('ITEM: Confirmed Item', $texts);
        $this->assertContains('ITEM: Consensus Item', $texts);
        $this->assertCount(2, $texts);
    }

    public function test_a_single_oversized_source_is_randomly_sampled_reproducibly(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->row("Gold {$i}", '8471', source: 'gold');
        }

        $rowsA = $this->export(['--cap' => 4, '--seed' => 42]);
        $rowsB = $this->export(['--cap' => 4, '--seed' => 42]);

        $textsA = collect($rowsA)->map(fn ($r) => $r['messages'][1]['content'])->sort()->values();
        $textsB = collect($rowsB)->map(fn ($r) => $r['messages'][1]['content'])->sort()->values();
        $this->assertCount(4, $textsA);
        $this->assertTrue($textsA->diff($textsB)->isEmpty(), 'same seed must reproduce the same sample');
    }

    public function test_services_are_never_capped(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->row("Service {$i}", null, isService: true);
        }

        $rows = $this->export(['--cap' => 3]);

        $this->assertCount(10, $rows);
    }

    public function test_default_output_path_is_dated(): void
    {
        $this->row('Dated Widget', '8471');
        $path = storage_path('app/finetune/train-'.date('Y-m-d').'.jsonl');

        $this->artisan('finetune:export-examples')->assertSuccessful();

        $this->assertFileExists($path);
        @unlink($path);
    }
}
