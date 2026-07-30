<?php

namespace App\Console\Commands;

use App\Models\AnswerCache;
use App\Services\Classify\Mechanisms\DirectLlmMechanism;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Export answer_cache (scope 0 — the SAME table the live classifier reads) as a
 * fine-tune-ready JSONL corpus.
 *
 * Every row in answer_cache IS already a verified answer — seeded (fedor/gold) or
 * written back live (auto:consensus / confirmed / ai_resolved_grounded) — so there is
 * nothing left to recompute: provenance is just `source`, and dedup is free (the
 * table's own (test_dataset_id, name_key) uniqueness).
 *
 * Balance: capped per 4-digit heading (--cap, default matches the field-tested gold-v1
 * value) to flatten frequency skew. This applies to EVERY source, not just the bulk
 * external references — ANY sufficiently large source skews toward common categories
 * (gold-v1 needed exactly this capping on its own 106k "verified" rows; live traffic
 * will show the same skew once its volume grows). Filled in TRUST-priority order per
 * heading — confirmed, then auto:consensus, then ai_resolved_grounded, then fedor, then
 * gold last — so a handful of live-verified answers are never crowded out by the much
 * larger bulk reference. Within a single source, if it alone exceeds the cap, a seeded
 * random sample is taken (the same method gold-v1/build.py used).
 *
 * Services carry no heading — kept in full, uncapped (gold-v1 did the same: teach the
 * good/service split from every example available; there's no "common service" skew
 * risk analogous to headings since services aren't grouped by code at all).
 */
class ExportFinetuneExamples extends Command
{
    /** Fill priority, highest trust first — matches memory_promotion.*.source values. */
    private const SOURCE_PRIORITY = ['confirmed', 'auto:consensus', 'ai_resolved_grounded', 'fedor', 'gold'];

    protected $signature = 'finetune:export-examples
        {--output= : Output JSONL path (default: storage/app/finetune/train-<date>.jsonl)}
        {--cap=200 : Max examples per 4-digit heading, trust-priority filled}
        {--seed=7 : Random seed for within-source sampling (reproducible re-runs)}';

    protected $description = 'Export answer_cache as a fine-tune-ready, heading-balanced JSONL training corpus';

    public function handle(): int
    {
        $cap = (int) $this->option('cap');
        $seed = (int) $this->option('seed');

        $rows = AnswerCache::where('test_dataset_id', 0)->get(['name', 'heading', 'is_service', 'source']);
        $services = $rows->where('is_service', true);
        $goods = $rows->where('is_service', false)->whereNotNull('heading');

        $selected = collect();
        $cappedHeadings = 0;
        foreach ($goods->groupBy('heading') as $group) {
            $before = $group->count();
            $filled = $this->fillHeading($group, $cap, $seed);
            if ($filled->count() < $before) {
                $cappedHeadings++;
            }
            $selected = $selected->merge($filled);
        }
        $selected = $selected->merge($services);

        $path = (string) ($this->option('output') ?: storage_path('app/finetune/train-'.date('Y-m-d').'.jsonl'));
        @mkdir(dirname($path), 0755, true);
        $fh = fopen($path, 'w');
        if ($fh === false) {
            $this->error("Could not open {$path} for writing.");

            return self::FAILURE;
        }
        foreach ($selected as $row) {
            fwrite($fh, json_encode($this->toExample($row), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        }
        fclose($fh);

        $this->info("Wrote {$selected->count()} examples to {$path}");
        $this->line('  goods:    '.($selected->count() - $services->count()).' across '.$goods->pluck('heading')->unique()->count().' headings ('.$cappedHeadings.' capped at '.$cap.')');
        $this->line('  services: '.$services->count().' (uncapped)');
        $this->line('  by source:');
        foreach ($selected->countBy('source')->sortDesc() as $source => $count) {
            $this->line("    {$source}: {$count}");
        }

        return self::SUCCESS;
    }

    /**
     * Fill one heading's bucket up to $cap, trust-priority order — see class docblock.
     *
     * @param  Collection<int, AnswerCache>  $group
     * @return Collection<int, AnswerCache>
     */
    private function fillHeading(Collection $group, int $cap, int $seed): Collection
    {
        $bySource = $group->groupBy('source');
        $selected = collect();

        foreach (self::SOURCE_PRIORITY as $source) {
            $remaining = $cap - $selected->count();
            if ($remaining <= 0) {
                break;
            }
            $pool = $bySource->get($source, collect());
            $selected = $selected->merge(
                $pool->count() <= $remaining ? $pool : collect($this->seededShuffle($pool->all(), $seed))->take($remaining)
            );
        }

        return $selected;
    }

    /**
     * A REPRODUCIBLE shuffle: Collection::shuffle() in this Laravel version dropped its
     * $seed parameter (now backed by Random\Randomizer's CSPRNG, unseedable) — construct
     * the Randomizer with an explicit Mt19937 engine instead, so the SAME seed always
     * produces the SAME sample (re-running the export is comparable across versions).
     *
     * @param  array<int, AnswerCache>  $items
     * @return array<int, AnswerCache>
     */
    private function seededShuffle(array $items, int $seed): array
    {
        return (new Randomizer(new Mt19937($seed)))->shuffleArray($items);
    }

    /** One answer_cache row -> a chat-format training example matching live inference. */
    private function toExample(AnswerCache $row): array
    {
        $answer = [
            'heading' => $row->is_service ? null : $row->heading,
            'kind' => $row->is_service ? 'service' : 'good',
            'confidence' => 1.0, // every example here is a VERIFIED answer, not a guess
            'reason' => mb_substr($row->name, 0, 60),
        ];

        return [
            'messages' => [
                ['role' => 'system', 'content' => DirectLlmMechanism::prompt('heading')],
                ['role' => 'user', 'content' => 'ITEM: '.$row->name],
                ['role' => 'assistant', 'content' => json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)],
            ],
        ];
    }
}
