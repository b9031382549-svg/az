<?php

namespace Tests\Feature\Classify;

use App\Models\ClassificationItem;
use App\Models\GoldLabel;
use App\Services\Classify\BenchmarkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BenchmarkServiceTest extends TestCase
{
    use RefreshDatabase;

    private function gold(string $source, string $name, array $attr): GoldLabel
    {
        return GoldLabel::create(['source' => $source, 'name' => $name, 'name_key' => GoldLabel::keyFor($name)] + $attr);
    }

    private function item(string $name, ?string $code, ?string $kind = 'good', string $res = 'agreed'): ClassificationItem
    {
        return ClassificationItem::create([
            'batch' => 'b', 'source_text' => $name, 'source_hash' => bin2hex(random_bytes(16)),
            'final_code' => $code, 'kind' => $kind, 'resolution' => $res,
        ]);
    }

    public function test_keyfor_normalizes_case_and_whitespace(): void
    {
        $this->assertSame(GoldLabel::keyFor('  Şpris   5ML '), GoldLabel::keyFor('şpris 5ml'));
    }

    public function test_ivan_full_code_agreement(): void
    {
        $this->gold('ivan', 'Kateter 24G', ['code' => '9018390000', 'heading' => '9018', 'is_service' => false]);
        $this->item('Kateter 24G', '9018390000');

        $a = app(BenchmarkService::class)->score()['sources']['ivan'];

        $this->assertSame(1, $a['agree']);
        $this->assertSame(1, $a['full_agree']);
        $this->assertSame(1, $a['heading_agree']);
    }

    public function test_ivan_is_scored_at_the_heading_not_the_full_code(): void
    {
        // Ivan's gold is a 10-digit code, but our classifier now answers with a 4-digit
        // heading — so Ivan is scored at the heading: a shared heading AGREES even though
        // the full codes differ.
        $this->gold('ivan', 'Şpris 5ml', ['code' => '9018311000', 'heading' => '9018', 'is_service' => false]);
        $this->item('Şpris 5ml', '9018'); // our answer is the 4-digit heading

        $a = app(BenchmarkService::class)->score()['sources']['ivan'];

        $this->assertSame(1, $a['agree']);          // heading match → agree
        $this->assertSame(0, $a['disagree']);
        $this->assertSame(1, $a['heading_agree']);
        $this->assertSame(0, $a['full_agree']);     // full 10-digit no longer drives status
    }

    public function test_item_without_a_final_code_is_no_code(): void
    {
        $this->gold('ivan', 'X', ['code' => '9018390000', 'heading' => '9018', 'is_service' => false]);
        $this->item('X', null, 'good', 'conflict');

        $a = app(BenchmarkService::class)->score()['sources']['ivan'];

        $this->assertSame(1, $a['no_code']);
        $this->assertSame(0, $a['agree']);
        $this->assertSame(0, $a['disagree']);
    }

    public function test_gold_without_a_matching_item_is_unclassified(): void
    {
        $this->gold('ivan', 'Never classified', ['code' => '9018390000', 'heading' => '9018', 'is_service' => false]);

        $a = app(BenchmarkService::class)->score()['sources']['ivan'];

        $this->assertSame(1, $a['total']);
        $this->assertSame(0, $a['matched']);
        $this->assertSame(1, $a['unclassified']);
    }

    public function test_ivan_row_without_a_gold_code_is_uncomparable_not_disagree(): void
    {
        // Ivan couldn't code this line (blank код каталога). We DID classify it — it
        // must not count as a disagreement (nothing to compare against).
        $this->gold('ivan', 'Su haqqı', ['code' => null, 'heading' => null, 'is_service' => false]);
        $this->item('Su haqqı', '2201100000');

        $a = app(BenchmarkService::class)->score()['sources']['ivan'];

        $this->assertSame(1, $a['no_ref']);
        $this->assertSame(0, $a['disagree']);
        $this->assertSame(0, $a['agree']);
    }

    public function test_fedor_good_matches_on_heading(): void
    {
        $this->gold('fedor', 'Barley 500g', ['heading' => '1104', 'chapter' => '11', 'is_service' => false, 'tier' => 'validated']);
        $this->item('Barley 500g', '1104130000');

        $a = app(BenchmarkService::class)->score()['sources']['fedor'];

        $this->assertSame(1, $a['agree']);
        $this->assertSame(1, $a['heading_agree']);
    }

    public function test_fedor_service_matches_on_the_service_flag(): void
    {
        $this->gold('fedor', 'Moon Light Hotel', ['heading' => null, 'is_service' => true, 'tier' => 'validated']);
        $this->item('Moon Light Hotel', null, 'service', 'agreed');

        $a = app(BenchmarkService::class)->score()['sources']['fedor'];

        $this->assertSame(1, $a['agree']);
        $this->assertSame(1, $a['service_agree']);
    }

    public function test_fedor_service_flag_mismatch_is_disagree(): void
    {
        $this->gold('fedor', 'Some works', ['heading' => null, 'is_service' => true, 'tier' => 'validated']);
        $this->item('Some works', '3004000000', 'good');

        $a = app(BenchmarkService::class)->score()['sources']['fedor'];

        $this->assertSame(1, $a['disagree']);
        $this->assertSame(0, $a['service_agree']);
        $this->assertSame(1, $a['service_total']);
    }

    public function test_overlap_triangulation_counts_agreement_with_both(): void
    {
        // Same product named in BOTH references, both at heading 1104; we hit both.
        $this->gold('ivan', 'Pearl barley', ['code' => '1104130000', 'heading' => '1104', 'is_service' => false]);
        $this->gold('fedor', 'Pearl barley', ['heading' => '1104', 'is_service' => false, 'tier' => 'validated']);
        $this->item('Pearl barley', '1104130000');

        $o = app(BenchmarkService::class)->score()['overlap'];

        $this->assertSame(1, $o['shared']);
        $this->assertSame(1, $o['classified']);
        $this->assertSame(1, $o['both']);
    }
}
