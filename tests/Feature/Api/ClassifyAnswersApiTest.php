<?php

namespace Tests\Feature\Api;

use App\Models\ApiRequestName;
use App\Models\ClassificationItem;
use App\Models\ImportBatch;
use App\Models\User;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// GET /api/classify/{id} gives one entry per distinct name as sent. The answer shows only once
// the automation is done with the item (answered / needs_review), and its similarity is the
// measured precision of the way it was found — a grounded web search far above an ungrounded one.
class ClassifyAnswersApiTest extends TestCase
{
    use RefreshDatabase;

    private const BATCH = '0b9c1f8e-1d7a-4a43-9a51-3d3e2a6c7f10';

    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('classify.mechanisms.enabled', ['vector', 'direct']);
        config()->set('classify.mechanisms.shadow', []);
        config()->set('classify.search_resolver.grounded_min_confidence', 0.9);
        config()->set('classify.vector.membership_k', 3);
        config()->set('api.similarity', [
            'human' => 0.99, 'memory' => 0.97, 'trash' => 0.98, 'consensus' => 0.93, 'sorter' => 0.9,
            'web_search' => 0.95, 'web_search_ungrounded' => 0.45, 'ensemble' => 0.7, 'ai' => 0.5,
        ]);
        $this->auth = ['Authorization' => 'Bearer '.User::factory()->create()->createToken('t', [ApiAbilities::CLASSIFY])->plainTextToken];
        ImportBatch::create(['key' => self::BATCH, 'label' => 'API · t', 'source' => 'api', 'status' => 'imported', 'item_count' => 0, 'meta' => ['lines' => 9]]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $results
     * @param  array<int, string>  $spellings  names as the caller sent them
     */
    private function item(array $spellings, array $attrs, array $results = []): ClassificationItem
    {
        $item = ClassificationItem::create($attrs + [
            'batch' => self::BATCH, 'source_text' => $spellings[0], 'source_hash' => bin2hex(random_bytes(32)), 'answered_at' => now(),
        ]);
        foreach ($results as $r) {
            $item->results()->create($r + ['status' => 'needs_review']);
        }
        foreach ($spellings as $name) {
            ApiRequestName::create(['batch' => self::BATCH, 'name' => $name, 'classification_item_id' => $item->id]);
        }

        return $item;
    }

    /** @return array<string, array<string, mixed>> entries by name */
    private function answers(string $query = ''): array
    {
        return collect($this->getJson('/api/classify/'.self::BATCH.$query, $this->auth)->assertOk()->json('classified_items'))
            ->keyBy('name')->all();
    }

    public function test_each_way_of_answering_carries_its_own_similarity(): void
    {
        $this->item(['X7 NANO BLACK 4 SIQARET YENI', 'x7 nano black 4 siqaret yeni'], ['resolution' => 'agreed', 'final_code' => '2402', 'kind' => 'good'], [
            ['mechanism' => 'direct', 'matched_code' => '2402', 'kind' => 'good', 'status' => 'auto_confirmed'],
            ['mechanism' => 'vector', 'matched_code' => '2402200000', 'kind' => 'good', 'candidates' => [['code' => '2402200000', 'kind' => 'good']]],
        ]);
        $this->item(['Marlboro Gold'], ['resolution' => 'agreed', 'final_code' => '2402', 'kind' => 'good'], [
            ['mechanism' => 'cache', 'matched_code' => '2402', 'status' => 'auto_confirmed', 'trace' => ['source' => 'confirmed']],
        ]);
        $this->item(['Hesab-faktura 123'], ['resolution' => 'trash'], [
            ['mechanism' => 'trash', 'status' => 'trash', 'trace' => ['rule' => 'paperwork']],
        ]);
        $this->item(['Ofis xidməti'], ['resolution' => 'confirmed', 'final_code' => '99', 'kind' => 'service', 'confirmed_at' => now()]);

        $a = $this->answers();

        $this->assertSame(['name' => 'X7 NANO BLACK 4 SIQARET YENI', 'category' => '2402', 'similarity' => 0.93, 'kind' => 'good', 'units' => [], 'status' => 'answered', 'method' => 'consensus'],
            array_diff_key($a['X7 NANO BLACK 4 SIQARET YENI'], ['reason' => 1]));
        $this->assertSame('2402', $a['x7 nano black 4 siqaret yeni']['category']); // a second spelling of the same item
        $this->assertSame([0.97, 'memory'], [$a['Marlboro Gold']['similarity'], $a['Marlboro Gold']['method']]);
        $this->assertSame([null, 'trash', 0.98, 'trash'], [$a['Hesab-faktura 123']['category'], $a['Hesab-faktura 123']['kind'], $a['Hesab-faktura 123']['similarity'], $a['Hesab-faktura 123']['method']]);
        $this->assertSame(['99', 'service', 0.99, 'human'], [$a['Ofis xidməti']['category'], $a['Ofis xidməti']['kind'], $a['Ofis xidməti']['similarity'], $a['Ofis xidməti']['method']]);
        $this->assertNotSame('', $a['Marlboro Gold']['reason']);
    }

    public function test_a_grounded_web_answer_rates_far_above_an_ungrounded_one(): void
    {
        $this->item(['Ansimar 400'], ['resolution' => 'ai_resolved', 'final_code' => '3004', 'kind' => 'good'], [
            ['mechanism' => 'direct', 'matched_code' => '3004900009', 'kind' => 'good'],
            ['mechanism' => 'vector', 'matched_code' => '3003900000', 'kind' => 'good'],
            ['mechanism' => 'search', 'matched_code' => '3004', 'kind' => 'good', 'confidence' => 0.97, 'status' => 'auto_confirmed', 'explanation' => 'A doxofylline tablet.'],
        ]);
        $this->item(['Fuga 2'], ['resolution' => 'ai_resolved', 'final_code' => '9504', 'kind' => 'good'], [
            ['mechanism' => 'direct', 'matched_code' => '3401110000', 'kind' => 'good'],
            ['mechanism' => 'vector', 'matched_code' => '3402500000', 'kind' => 'good'],
            ['mechanism' => 'search', 'matched_code' => '9504', 'kind' => 'good', 'confidence' => 0.99, 'status' => 'auto_confirmed', 'explanation' => 'A video game.'],
        ]);
        $this->item(['Doubtful'], ['resolution' => 'ai_resolved', 'final_code' => '3004', 'kind' => 'good'], [
            ['mechanism' => 'direct', 'matched_code' => '3004900009', 'kind' => 'good'],
            ['mechanism' => 'search', 'matched_code' => '3004', 'kind' => 'good', 'confidence' => 0.85, 'status' => 'auto_confirmed'],
        ]);

        $a = $this->answers();

        $this->assertSame(['web_search', 0.95], [$a['Ansimar 400']['method'], $a['Ansimar 400']['similarity']]);
        $this->assertSame(['web_search', 0.45], [$a['Fuga 2']['method'], $a['Fuga 2']['similarity']]);     // its heading no mechanism proposed
        $this->assertSame(0.45, $a['Doubtful']['similarity']);                                              // below the grounded confidence bar
    }

    public function test_no_answer_before_the_automation_is_done_or_when_it_gave_up(): void
    {
        $this->item(['Still going'], ['resolution' => 'pending', 'answered_at' => null], [
            ['mechanism' => 'direct', 'matched_code' => '2402', 'kind' => 'good'],
        ]);
        $this->item(['Undecided'], ['resolution' => 'conflict'], [
            ['mechanism' => 'direct', 'matched_code' => '2402', 'kind' => 'good'],
            ['mechanism' => 'vector', 'matched_code' => '8471300000', 'kind' => 'good', 'candidates' => [['code' => '8471300000', 'kind' => 'good']]],
            // The web search ran but was not confident enough — the item waits for a human.
            ['mechanism' => 'search', 'matched_code' => '2402', 'kind' => 'good', 'confidence' => 0.6, 'status' => 'needs_review'],
        ]);

        $a = $this->answers();

        $this->assertSame(['category' => null, 'similarity' => null, 'kind' => null, 'status' => 'pending', 'method' => 'in_progress'],
            array_intersect_key($a['Still going'], array_flip(['category', 'similarity', 'kind', 'status', 'method'])));
        $this->assertSame(['category' => null, 'similarity' => null, 'kind' => null, 'status' => 'needs_review', 'method' => 'needs_human'],
            array_intersect_key($a['Undecided'], array_flip(['category', 'similarity', 'kind', 'status', 'method'])));
    }

    public function test_returns_the_units_the_lines_carried_for_each_name(): void
    {
        $item = $this->item(['Su 0.5L'], ['resolution' => 'pending', 'answered_at' => null]);
        ApiRequestName::where('classification_item_id', $item->id)->update(['units' => json_encode(['ədəd', 'blok'])]);
        $this->item(['Çörək'], ['resolution' => 'pending', 'answered_at' => null]);

        $a = $this->answers();

        $this->assertSame(['ədəd', 'blok'], $a['Su 0.5L']['units']);
        $this->assertSame([], $a['Çörək']['units']);
    }

    public function test_pages_through_the_names_in_the_order_they_were_sent(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $this->item([$name], ['resolution' => 'pending', 'answered_at' => null]);
        }

        $page = $this->getJson('/api/classify/'.self::BATCH.'?offset=1&limit=2', $this->auth)->assertOk();

        $page->assertJsonPath('offset', 1)->assertJsonPath('limit', 2)->assertJsonPath('names', 5);
        $this->assertSame(['b', 'c'], array_column($page->json('classified_items'), 'name'));

        config()->set('api.classify.max_page_size', 3);
        $this->getJson('/api/classify/'.self::BATCH.'?limit=50', $this->auth)->assertJsonPath('limit', 3)->assertJsonCount(3, 'classified_items');
    }
}
