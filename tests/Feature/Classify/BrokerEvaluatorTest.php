<?php

namespace Tests\Feature\Classify;

use App\Models\ClassificationItem;
use App\Services\Classify\BrokerEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrokerEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private function confirmed(string $goldCode): ClassificationItem
    {
        return ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'x', 'source_hash' => bin2hex(random_bytes(32)),
            'resolution' => 'confirmed', 'final_code' => $goldCode,
        ]);
    }

    public function test_scores_mechanisms_against_confirmed_items(): void
    {
        // gold 8471300000: vector exact; broker same heading (8471) different leaf.
        $i = $this->confirmed('8471300000');
        $i->results()->create(['mechanism' => 'vector', 'matched_code' => '8471300000', 'confidence' => 0.9, 'usage' => ['total_tokens' => 10]]);
        $i->results()->create(['mechanism' => 'broker', 'matched_code' => '8471490000', 'confidence' => 0.7, 'usage' => ['total_tokens' => 50]]);

        // A non-confirmed item is ignored (not part of the gold set).
        ClassificationItem::create(['batch' => 'b', 'source_text' => 'y', 'source_hash' => bin2hex(random_bytes(32)), 'resolution' => 'agreed', 'final_code' => '3004000000']);

        $r = (new BrokerEvaluator)->evaluate(['vector', 'broker']);

        $this->assertSame(1, $r['sampleSize']);
        $this->assertSame(1, $r['mechanisms']['vector']['exact']);
        $this->assertSame(0, $r['mechanisms']['broker']['exact']);
        $this->assertSame(1, $r['mechanisms']['broker']['p4']);   // 8471 prefix matches
        $this->assertSame(0, $r['mechanisms']['broker']['p6']);   // 847130 != 847149
        $this->assertSame(50, $r['mechanisms']['broker']['avgTokens']);
        $this->assertSame(1, $r['agreement']['both']);
        $this->assertSame(0, $r['agreement']['match']);
    }

    public function test_confidence_buckets_split_by_threshold(): void
    {
        $i = $this->confirmed('8471300000');
        $i->results()->create(['mechanism' => 'broker', 'matched_code' => '8471300000', 'confidence' => 0.9]);

        $buckets = collect((new BrokerEvaluator)->evaluate(['broker'])['mechanisms']['broker']['buckets'])
            ->keyBy('label');

        $this->assertSame(1, $buckets['>=0.8']['n']);
        $this->assertSame(1, $buckets['>=0.8']['exact']);
        $this->assertSame(0, $buckets['<0.6']['n']);
    }

    public function test_command_runs_and_handles_empty_gold_set(): void
    {
        $this->artisan('broker:eval')->assertSuccessful();
    }
}
