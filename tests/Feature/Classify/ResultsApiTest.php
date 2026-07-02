<?php

namespace Tests\Feature\Classify;

use App\Models\ClassificationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultsApiTest extends TestCase
{
    use RefreshDatabase;

    private string $key = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.results_api.key', $this->key);
    }

    private function seedItem(string $batch = 'b1'): ClassificationItem
    {
        $item = ClassificationItem::create([
            'batch' => $batch, 'source_text' => 'noutbuk',
            'source_hash' => bin2hex(random_bytes(32)), 'resolution' => 'conflict',
        ]);
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '8471300000', 'confidence' => 0.9, 'status' => 'needs_review', 'trace' => ['input' => 'noutbuk', 'gate' => ['status' => 'needs_review']]]);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '8528720000', 'confidence' => 0.6, 'status' => 'needs_review', 'trace' => ['steps' => []]]);

        return $item;
    }

    public function test_requires_a_valid_key(): void
    {
        $item = $this->seedItem();
        $this->getJson("/api/results/{$item->id}")->assertStatus(401);
        $this->getJson("/api/results/{$item->id}", ['X-Api-Key' => 'wrong'])->assertStatus(401);
    }

    public function test_result_returns_item_with_traces(): void
    {
        $item = $this->seedItem();

        $this->getJson("/api/results/{$item->id}", ['X-Api-Key' => $this->key])
            ->assertOk()
            ->assertJsonPath('id', $item->id)
            ->assertJsonPath('resolution', 'conflict')
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.mechanism', 'vector')
            ->assertJsonPath('results.0.trace.gate.status', 'needs_review');
    }

    public function test_result_accepts_bearer_token(): void
    {
        $item = $this->seedItem();
        $this->getJson("/api/results/{$item->id}", ['Authorization' => 'Bearer '.$this->key])->assertOk();
    }

    public function test_upload_lists_items(): void
    {
        $this->seedItem('up1');
        $this->seedItem('up1');

        $this->getJson('/api/uploads/up1', ['X-Api-Key' => $this->key])
            ->assertOk()
            ->assertJsonPath('batch', 'up1')
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.mechanisms.vector.code', '8471300000');
    }

    public function test_unknown_item_is_404(): void
    {
        $this->getJson('/api/results/999999', ['X-Api-Key' => $this->key])->assertStatus(404);
    }

    public function test_disabled_when_key_unset(): void
    {
        config()->set('services.results_api.key', '');
        $item = $this->seedItem();
        $this->getJson("/api/results/{$item->id}", ['X-Api-Key' => ''])->assertStatus(401);
    }
}
