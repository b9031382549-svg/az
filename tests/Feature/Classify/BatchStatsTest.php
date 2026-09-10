<?php

namespace Tests\Feature\Classify;

use App\Models\ClassificationItem;
use App\Services\Classify\BatchStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchStatsTest extends TestCase
{
    use RefreshDatabase;

    private function item(array $attrs): ClassificationItem
    {
        return ClassificationItem::create(array_merge([
            'batch' => 'b', 'source_hash' => bin2hex(random_bytes(16)), 'source_text' => 'x', 'resolution' => 'pending',
        ], $attrs));
    }

    public function test_funnel_splits_items_by_derived_source(): void
    {
        // Memory (cache hit): agreed with a 'cache' trace.
        $m = $this->item(['resolution' => 'agreed', 'final_code' => '1104', 'answered_at' => now()]);
        $m->results()->create(['mechanism' => 'cache', 'matched_code' => '1104', 'status' => 'auto_confirmed']);

        // Local AI: agreed via consensus (no cache trace), promoted to memory.
        $l = $this->item(['resolution' => 'agreed', 'final_code' => '7411', 'answered_at' => now(), 'memory_promoted_at' => now()]);
        $l->results()->create(['mechanism' => 'vector', 'matched_code' => '7411300000', 'status' => 'auto_confirmed']);

        // Ensemble: ai_resolved, the committed 'ensemble' trace IS the final answer. Not promoted.
        $e = $this->item(['resolution' => 'ai_resolved', 'final_code' => '8802', 'answered_at' => now()]);
        $e->results()->create(['mechanism' => 'ensemble', 'matched_code' => '8802', 'status' => 'auto_confirmed']);

        // Web: ai_resolved via search (no committed ensemble), grounded-promoted to memory.
        $w = $this->item(['resolution' => 'ai_resolved', 'final_code' => '99', 'answered_at' => now(), 'memory_promoted_at' => now()]);
        $w->results()->create(['mechanism' => 'search', 'matched_code' => '99', 'status' => 'auto_confirmed']);

        // Human: a done conflict (resolver ran, left it) — answered but no auto answer.
        $this->item(['resolution' => 'conflict', 'answered_at' => now()]);

        // Still processing: never answered.
        $this->item(['resolution' => 'pending']);

        $stats = app(BatchStats::class)->for('b');

        $this->assertSame(6, $stats['total']);
        $this->assertSame(5, $stats['answered']);
        $this->assertSame(1, $stats['processing']);
        $this->assertFalse($stats['complete']);

        $ran = collect($stats['rows'])->pluck('ran', 'key');
        $this->assertSame(1, $ran['memory']);
        $this->assertSame(1, $ran['local_ai']);
        $this->assertSame(1, $ran['ensemble']);
        $this->assertSame(1, $ran['web']);
        $this->assertSame(1, $ran['human']);

        // Rows always sum to the answered total (human is the remainder).
        $this->assertSame(5, collect($stats['rows'])->sum('ran'));

        // "Sent to memory & training" = only what actually wrote back.
        $mem = collect($stats['rows'])->pluck('memory', 'key');
        $this->assertNull($mem['memory']);                 // cache is already in memory
        $this->assertSame(1, $mem['local_ai']);            // agreed consensus promoted
        $this->assertSame(0, $mem['ensemble']);            // ensemble commits are not promoted
        $this->assertSame(1, $mem['web']);                 // grounded search promoted
        $this->assertNull($mem['human']);
    }

    public function test_recognition_time_spans_start_to_last_answered(): void
    {
        $start = now()->subMinutes(5);
        $a = $this->item(['resolution' => 'agreed', 'final_code' => '1104']);
        $a->forceFill(['created_at' => $start, 'answered_at' => $start->copy()->addMinutes(2)])->save();
        $b = $this->item(['resolution' => 'agreed', 'final_code' => '1105']);
        $b->forceFill(['created_at' => $start->copy()->addSeconds(30), 'answered_at' => $start->copy()->addMinutes(4)])->save();

        $stats = app(BatchStats::class)->for('b');

        // min(created_at)=start, max(answered_at)=start+4m → 240s.
        $this->assertSame(240, $stats['seconds']);
    }

    public function test_percentages_are_over_the_batch_total(): void
    {
        foreach (range(1, 8) as $i) {
            $this->item(['resolution' => 'agreed', 'final_code' => '7411', 'answered_at' => now()]);
        }
        foreach (range(1, 2) as $i) {
            $this->item(['resolution' => 'conflict', 'answered_at' => now()]);
        }

        $stats = app(BatchStats::class)->for('b');
        $pct = collect($stats['rows'])->pluck('pct', 'key');

        $this->assertSame(80.0, $pct['local_ai']); // 8 of 10
        $this->assertSame(20.0, $pct['human']);    // 2 of 10
        $this->assertTrue($stats['complete']);
    }
}
