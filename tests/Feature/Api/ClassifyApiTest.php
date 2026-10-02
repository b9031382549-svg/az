<?php

namespace Tests\Feature\Api;

use App\Jobs\FeedUploadClassificationJob;
use App\Jobs\IngestApiRequestJob;
use App\Models\ApiRequestName;
use App\Models\ClassificationItem;
use App\Models\EInvoice;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Api\ClassifyRequests;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// POST /api/classify accepts the items and answers at once with the request id (202); a worker
// job creates the items, one per name case/space-insensitively, while the API keeps every
// spelling it was sent; GET /api/classify/{id} reports accepted → processing → done.
class ClassifyApiTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private User $user;

    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->dir = sys_get_temp_dir().'/az-api-'.bin2hex(random_bytes(4));
        config()->set('uploads.directory', $this->dir);
        $this->user = User::factory()->create();
        $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('Client integration', [ApiAbilities::CLASSIFY])->plainTextToken];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param  array<int, string>  $names */
    private function send(array $names): string
    {
        return $this->postJson('/api/classify', ['items' => array_map(fn ($n) => ['name' => $n], $names)], $this->auth)
            ->assertStatus(202)
            ->json('request_id');
    }

    private function ingest(string $id): void
    {
        app(ClassifyRequests::class)->ingest($id);
    }

    public function test_needs_a_token_with_the_classify_ability(): void
    {
        $this->postJson('/api/classify', ['items' => [['name' => 'x']]])->assertStatus(401);

        $results = ['Authorization' => 'Bearer '.$this->user->createToken('results only', [ApiAbilities::RESULTS])->plainTextToken];
        $this->postJson('/api/classify', ['items' => [['name' => 'x']]], $results)->assertStatus(403);
    }

    public function test_accepts_at_once_and_queues_the_ingest(): void
    {
        $response = $this->postJson('/api/classify', ['items' => [['name' => 'Marlboro Gold'], ['name' => 'ANSIMAR-400mg-N20-TAB']]], $this->auth);

        $id = $response->assertStatus(202)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('lines', 2)
            ->json('request_id');
        $this->assertTrue(str_ends_with((string) $response->headers->get('Location'), '/api/classify/'.$id));

        $batch = ImportBatch::where('key', $id)->sole();
        $this->assertSame('api', $batch->source);
        $this->assertSame('API · Client integration', $batch->label);
        $this->assertSame($this->user->id, $batch->user_id);
        Queue::assertPushed(IngestApiRequestJob::class, fn ($job) => $job->batch === $id);
        $this->assertSame(0, ClassificationItem::count()); // nothing is created inside the request
        $this->assertDatabaseHas('activity_log', ['action' => 'classify.api', 'subject_id' => $batch->id]);
    }

    public function test_only_the_name_is_required(): void
    {
        $this->postJson('/api/classify', ['items' => [['name' => 'Su', 'unit' => 'litr', 'number' => '123']]], $this->auth)
            ->assertStatus(202);
    }

    public function test_rejects_a_body_without_valid_items(): void
    {
        $this->postJson('/api/classify', [], $this->auth)->assertStatus(422)->assertJsonValidationErrors('items');
        $this->postJson('/api/classify', ['items' => []], $this->auth)->assertStatus(422)->assertJsonValidationErrors('items');
        $this->postJson('/api/classify', ['items' => [['name' => 'ok'], ['unit' => 'kq'], ['name' => '  '], 'text']], $this->auth)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.1.name', 'items.2.name', 'items.3.name'])
            ->assertJsonMissingValidationErrors('items.0.name');

        config()->set('api.classify.max_items', 2);
        $this->postJson('/api/classify', ['items' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']]], $this->auth)
            ->assertStatus(422)->assertJsonValidationErrors('items');

        config()->set('api.classify.max_name_length', 5);
        $this->postJson('/api/classify', ['items' => [['name' => 'too long']]], $this->auth)
            ->assertStatus(422)->assertJsonValidationErrors('items.0.name');

        $this->assertSame(0, ImportBatch::count());
    }

    public function test_ingest_keeps_every_spelling_but_classifies_each_name_once(): void
    {
        $id = $this->send(['Marlboro Gold', 'MARLBORO  GOLD ', 'Marlboro Gold', '0123', '123']);

        $this->ingest($id);

        $this->assertSame(['Marlboro Gold', 'MARLBORO  GOLD ', '0123', '123'], ApiRequestName::where('batch', $id)->orderBy('id')->pluck('name')->all());
        $this->assertSame(3, ClassificationItem::where('batch', $id)->count()); // the two Marlboro spellings share one item
        $marlboro = ApiRequestName::where('batch', $id)->where('name', 'like', '%arlboro%')->orWhere('name', 'like', '%ARLBORO%')->pluck('classification_item_id')->unique();
        $this->assertCount(1, $marlboro);

        $batch = ImportBatch::where('key', $id)->sole();
        $this->assertSame('imported', $batch->status);
        $this->assertSame(3, $batch->item_count);
        Queue::assertPushed(FeedUploadClassificationJob::class, fn ($job) => $job->batch === $id);
        $this->assertSame([], File::glob($this->dir.'/api-*.ndjson')); // the saved body is gone
    }

    public function test_ingest_is_safe_to_run_twice(): void
    {
        $id = $this->send(['Su', 'Çörək']);
        $this->ingest($id);
        ImportBatch::where('key', $id)->update(['status' => ClassifyRequests::ACCEPTED]); // as if it had to run again
        File::put($this->dir.'/api-'.$id.'.ndjson', "{\"name\":\"Su\"}\n{\"name\":\"Çörək\"}\n");

        $this->ingest($id);

        $this->assertSame(2, ApiRequestName::where('batch', $id)->count());
        $this->assertSame(2, ClassificationItem::where('batch', $id)->count());
    }

    public function test_lines_with_invoice_fields_go_to_e_invoices_like_a_sablon_upload(): void
    {
        $id = $this->postJson('/api/classify', ['items' => [
            ['name' => 'Su 0.5L', 'unit' => 'ədəd', 'quantity' => '12', 'series' => 'MT', 'number' => '1001',
                'invoice_date' => '01.09.2026', 'total_amount' => '1 234,50', 'supplier_tin' => 1234567890, 'price' => 99],
            ['name' => 'Su 0.5L', 'unit' => ' blok ', 'series' => 'MT', 'number' => '1002'],
            ['name' => 'Çörək'],
        ]], $this->auth)->assertStatus(202)->json('request_id');

        $this->ingest($id);

        $item = ApiRequestName::where('batch', $id)->where('name', 'Su 0.5L')->sole();
        $lines = EInvoice::where('import_batch', $id)->orderBy('id')->get();
        $this->assertCount(2, $lines); // the name-only line has no invoice to keep
        $this->assertSame(
            ['Su 0.5L', 'ədəd', 12.0, 'MT|1001', '2026-09-01', 1234.5, '1234567890', $item->classification_item_id],
            [$lines[0]->item_name, $lines[0]->unit, (float) $lines[0]->quantity, $lines[0]->invoice_key, (string) $lines[0]->invoice_date?->format('Y-m-d'), (float) $lines[0]->total_amount, $lines[0]->supplier_tin, $lines[0]->classification_item_id],
        );
        $this->assertSame(['ədəd', 'blok'], $item->units); // every unit its lines carried, as sent (trimmed)
        $this->assertNull(ApiRequestName::where('batch', $id)->where('name', 'Çörək')->sole()->units);

        $meta = ImportBatch::where('key', $id)->sole()->meta;
        $this->assertSame([2, 0], [$meta['invoice_lines'], $meta['skipped_lines']]);
    }

    public function test_an_invoice_another_upload_loaded_is_not_written_twice_but_its_names_are_answered(): void
    {
        EInvoice::create(['item_name' => 'Su', 'series' => 'MT', 'number' => '1001', 'invoice_key' => 'MT|1001', 'import_batch' => 'an-earlier-upload']);
        $id = $this->postJson('/api/classify', ['items' => [['name' => 'Su', 'series' => 'MT', 'number' => '1001']]], $this->auth)
            ->assertStatus(202)->json('request_id');

        $this->ingest($id);

        $this->assertSame(0, EInvoice::where('import_batch', $id)->count());
        $this->assertSame(1, ImportBatch::where('key', $id)->sole()->meta['skipped_lines']);
        $this->assertSame(1, ApiRequestName::where('batch', $id)->count());
        $this->assertSame(1, ClassificationItem::where('batch', $id)->count());
    }

    public function test_a_rerun_does_not_double_the_invoice_lines(): void
    {
        $id = $this->postJson('/api/classify', ['items' => [['name' => 'Su', 'unit' => 'litr', 'series' => 'MT', 'number' => '7']]], $this->auth)
            ->assertStatus(202)->json('request_id');
        $body = File::get($this->dir.'/api-'.$id.'.ndjson');
        $this->ingest($id);
        ImportBatch::where('key', $id)->update(['status' => ClassifyRequests::ACCEPTED]);
        File::put($this->dir.'/api-'.$id.'.ndjson', $body);

        $this->ingest($id);

        $this->assertSame(1, EInvoice::where('import_batch', $id)->count());
    }

    public function test_status_goes_from_accepted_to_processing_to_done(): void
    {
        $id = $this->send(['Su', 'Çörək', 'su']);
        $this->getJson("/api/classify/{$id}", $this->auth)
            ->assertOk()
            ->assertJson(['request_id' => $id, 'status' => 'accepted', 'lines' => 3, 'names' => 0, 'answered' => 0, 'error' => null]);

        $this->ingest($id);
        $this->getJson("/api/classify/{$id}", $this->auth)
            ->assertJson(['status' => 'processing', 'lines' => 3, 'names' => 3, 'answered' => 0]);

        $su = ApiRequestName::where('batch', $id)->where('name', 'Su')->value('classification_item_id');
        ClassificationItem::whereKey($su)->update(['answered_at' => now()]);
        $this->getJson("/api/classify/{$id}", $this->auth)
            ->assertJson(['status' => 'processing', 'names' => 3, 'answered' => 2]); // "Su" and "su" are one item

        ClassificationItem::where('batch', $id)->update(['answered_at' => now()]);
        $this->getJson("/api/classify/{$id}", $this->auth)->assertJson(['status' => 'done', 'answered' => 3]);
    }

    public function test_a_lost_body_fails_the_request(): void
    {
        $id = $this->send(['Su']);
        File::delete($this->dir.'/api-'.$id.'.ndjson');

        $this->ingest($id);

        $this->getJson("/api/classify/{$id}", $this->auth)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'The request body was lost before it was read. Please send it again.');
    }

    public function test_unknown_or_non_api_requests_are_404(): void
    {
        $this->getJson('/api/classify/'.fake()->uuid(), $this->auth)->assertStatus(404);

        $manual = ImportBatch::create(['key' => fake()->uuid(), 'label' => 'Manual entry', 'source' => 'manual', 'item_count' => 0]);
        $this->getJson('/api/classify/'.$manual->key, $this->auth)->assertStatus(404);
    }

    public function test_tend_redispatches_a_request_whose_ingest_was_lost(): void
    {
        $id = $this->send(['Su']);
        Queue::fake(); // forget the dispatch from accept()
        ImportBatch::where('key', $id)->update(['updated_at' => now()->subMinutes(11)]);

        app(ClassifyRequests::class)->tend();

        Queue::assertPushed(IngestApiRequestJob::class, fn ($job) => $job->batch === $id);
    }
}
