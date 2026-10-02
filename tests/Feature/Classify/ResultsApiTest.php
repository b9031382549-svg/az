<?php

namespace Tests\Feature\Classify;

use App\Models\ClassificationItem;
use App\Models\User;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultsApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> a Bearer token with the `results` ability */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $token = User::factory()->create()->createToken('tooling', [ApiAbilities::RESULTS])->plainTextToken;
        $this->auth = ['Authorization' => 'Bearer '.$token];
        config()->set('classify.search_resolver.enabled', false); // a conflict is then terminal
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

    public function test_requires_a_valid_token(): void
    {
        $item = $this->seedItem();
        $this->getJson("/api/results/{$item->id}")->assertStatus(401);
        $this->getJson("/api/results/{$item->id}", ['Authorization' => 'Bearer 1|wrong'])->assertStatus(401);
    }

    public function test_a_token_without_the_results_ability_is_forbidden(): void
    {
        $item = $this->seedItem();
        $token = User::factory()->create()->createToken('classify only', [ApiAbilities::CLASSIFY])->plainTextToken;

        $this->getJson("/api/results/{$item->id}", ['Authorization' => 'Bearer '.$token])->assertStatus(403);
    }

    public function test_the_old_static_key_header_no_longer_opens_it(): void
    {
        $item = $this->seedItem();
        $this->getJson("/api/results/{$item->id}", ['X-Api-Key' => 'test-api-key'])->assertStatus(401);
    }

    public function test_result_returns_item_with_traces(): void
    {
        $item = $this->seedItem();

        $this->getJson("/api/results/{$item->id}", $this->auth)
            ->assertOk()
            ->assertJsonPath('id', $item->id)
            ->assertJsonPath('resolution', 'conflict')
            ->assertJsonPath('method', 'needs_human')
            ->assertJsonPath('reason', 'The methods diverged — a human needs to decide.')
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.mechanism', 'vector')
            ->assertJsonPath('results.0.trace.gate.status', 'needs_review');
    }

    public function test_upload_lists_items(): void
    {
        $this->seedItem('up1');
        $this->seedItem('up1');

        $this->getJson('/api/uploads/up1', $this->auth)
            ->assertOk()
            ->assertJsonPath('batch', 'up1')
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.method', 'needs_human')
            ->assertJsonPath('items.0.mechanisms.vector.code', '8471300000');
    }

    public function test_unknown_item_is_404(): void
    {
        $this->getJson('/api/results/999999', $this->auth)->assertStatus(404);
    }
}
