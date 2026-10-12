<?php

namespace Tests\Feature\Translate;

use App\Models\ClassificationItem;
use App\Models\GpuServer;
use App\Models\ItemTranslation;
use App\Models\LlmUsage;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Services\Llm\OpenRouterClient;
use App\Services\Translate\ItemTranslator;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

// Item names are translated on our GPU while a slot is serving (gpu:base), and on the old
// flow — the gpu: fallback, Token Factory — otherwise. Every row remembers which model wrote
// it, so Llama's translations can be redone once a better route exists.
class ItemTranslatorTest extends TestCase
{
    use RefreshDatabase;

    private const FALLBACK = 'deepseek-ai/DeepSeek-V4-Flash-0731';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('classify.translate_model', 'gpu:base');
        config()->set('services.openrouter.base_url', 'https://openrouter.test/api/v1');
        config()->set('services.openrouter.api_key', 'or-key');
        config()->set('services.openrouter.model', 'openai/gpt-4o-mini');
        config()->set('services.nebius.base_url', 'https://tokenfactory.test/v1');
        config()->set('services.nebius.api_key', 'tf-key');
        config()->set('services.nebius.fallback_model', self::FALLBACK);

        // What each route really answered for "Armud" (pear) on 2026-10-12.
        Http::fake([
            'gpu-a.test/*' => $this->answer('base', 'quince', 'айва'),
            'tokenfactory.test/*' => $this->answer(self::FALLBACK, 'pear', 'груша'),
        ]);
    }

    public function test_translates_on_the_gpu_while_a_slot_is_serving(): void
    {
        $this->serveOnGpu();

        $row = app(ItemTranslator::class)->ensure('Armud');

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://gpu-a.test/v1/chat/completions')
            && $r->hasHeader('Authorization', 'Bearer sk-a')
            && $r['model'] === 'base');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'tokenfactory.test'));
        $this->assertSame(['quince', 'айва', 'base'], [$row->en, $row->ru, $row->model]);
    }

    public function test_without_a_serving_gpu_it_keeps_the_old_flow(): void
    {
        $row = app(ItemTranslator::class)->ensure('Armud');

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://tokenfactory.test/v1/chat/completions')
            && $r['model'] === self::FALLBACK);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openrouter.test'));
        $this->assertSame(['pear', 'груша', self::FALLBACK], [$row->en, $row->ru, $row->model]);
    }

    public function test_a_failed_call_is_logged_under_the_translation_model(): void
    {
        $this->mock(OpenRouterClient::class)
            ->shouldReceive('jsonWithUsage')
            ->andThrow(new RuntimeException('GPU:A request failed (502)'));

        $row = app(ItemTranslator::class)->ensure('Armud');

        $this->assertNull($row->en);
        $log = LlmUsage::where('purpose', 'translate_item')->sole();
        $this->assertSame('error', $log->status);
        // Not OpenRouter's default model — that label made GPU / Token Factory errors look
        // like OpenRouter calls in the logs.
        $this->assertSame('gpu:base', $log->model);
    }

    public function test_the_backfill_fills_pipeline_names_without_a_complete_translation(): void
    {
        $this->entry('Armud');                                                    // its call failed
        $this->entry('Fəhlə əlcəyi', 'Work glove', 'Рабочая перчатка', 'openai/gpt-4o');
        $this->item('upload:1', 'Armud');
        $this->item('upload:1', 'koynək');                                        // its job never ran
        $this->item('upload:1', 'Fəhlə əlcəyi');
        $run = $this->makeRun();
        $this->item(TestRun::batchKey($run->id), 'Sınaq adı', $run->id);          // Testing tab: never translated

        $this->artisan('items:translate')->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertSame('pear', ItemTranslation::where('source_text', 'Armud')->value('en'));
        $this->assertSame('pear', ItemTranslation::where('source_text', 'koynək')->value('en'));
        $this->assertSame('Work glove', ItemTranslation::where('source_text', 'Fəhlə əlcəyi')->value('en'));
        $this->assertFalse(ItemTranslation::where('source_text', 'Sınaq adı')->exists());
    }

    public function test_redo_model_retranslates_only_the_rows_that_model_wrote(): void
    {
        $this->entry('Armud', 'quince', 'айва', 'base');
        $this->entry('Fəhlə əlcəyi', 'Work glove', 'Рабочая перчатка', 'openai/gpt-4o');

        $this->artisan('items:translate', ['--redo-model' => 'base'])->assertSuccessful();

        Http::assertSentCount(1);
        $armud = ItemTranslation::where('source_text', 'Armud')->first();
        $this->assertSame(['pear', 'груша', self::FALLBACK], [$armud->en, $armud->ru, $armud->model]);
        $this->assertSame('Work glove', ItemTranslation::where('source_text', 'Fəhlə əlcəyi')->value('en'));
    }

    public function test_redo_warns_while_the_route_still_leads_to_that_model(): void
    {
        $this->serveOnGpu();
        $this->entry('Armud', 'quince', 'айва', 'base');

        $this->artisan('items:translate', ['--redo-model' => 'base'])
            ->expectsOutputToContain('the redo will reproduce them')
            ->assertSuccessful();
    }

    private function serveOnGpu(): void
    {
        GpuServer::query()->where('slot', 'A')->update([
            'is_active' => true,
            'role' => 'serving',
            'status' => GpuServer::STATUS_SERVING,
            'base_url' => 'https://gpu-a.test/v1',
            'api_key' => 'sk-a',
        ]);
    }

    private function answer(string $model, string $en, string $ru): PromiseInterface
    {
        return Http::response([
            'model' => $model,
            'choices' => [['message' => ['content' => json_encode(['en' => $en, 'ru' => $ru], JSON_UNESCAPED_UNICODE)]]],
            'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 30, 'total_tokens' => 530],
        ]);
    }

    private function entry(string $text, ?string $en = null, ?string $ru = null, ?string $model = null): void
    {
        ItemTranslation::create([
            'source_hash' => ItemTranslation::hashFor($text),
            'source_text' => $text,
            'en' => $en,
            'ru' => $ru,
            'model' => $model,
        ]);
    }

    private function item(string $batch, string $text, ?int $testRunId = null): void
    {
        ClassificationItem::create([
            'batch' => $batch,
            'source_hash' => ItemTranslation::hashFor($text),
            'source_text' => $text,
            'resolution' => 'pending',
            'test_run_id' => $testRunId,
        ]);
    }

    private function makeRun(): TestRun
    {
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => ['enabled' => ['vector']]]);

        return TestRun::create([
            'test_dataset_id' => $dataset->id, 'description' => 'r', 'batch' => 'tmp',
            'mechanisms' => [], 'config' => [], 'status' => 'running', 'total' => 0,
        ]);
    }
}
