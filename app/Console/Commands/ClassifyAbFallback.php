<?php

namespace App\Console\Commands;

use App\Services\Classify\CatalogRetriever;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Dev-only A/B for picking the re-ranker model. Candidates are retrieved ONCE
 * per item (expand + hybrid retrieve), then EACH model re-ranks the SAME set —
 * a strict model-vs-model comparison. Models prefixed "ollama/" run on the
 * local Ollama OpenAI-compatible endpoint instead of OpenRouter (so a local
 * tier-1 LLM can be compared head-to-head with cloud candidates).
 * Throwaway tool, safe to delete.
 */
class ClassifyAbFallback extends Command
{
    protected $signature = 'classify:ab-fallback
        {--per=2 : Items sampled per GROUP}
        {--models=openai/gpt-4o,qwen/qwen-2.5-7b-instruct,qwen/qwen-2.5-72b-instruct : Comma-separated OpenRouter model slugs}
        {--file=start-data/task 2/e-invoice_Data_samples_goods.xlsx}';

    protected $description = 'Compare candidate re-ranker models (cloud + local) on one shared candidate set';

    /** @var array<string, array<int, string>> GROUP -> plausible HS chapters */
    private array $expected = [
        'BAKERY' => ['19', '17', '18', '21'],
        'CANNED FISH' => ['16', '03'],
        'WIPES' => ['48', '56', '34'],
        'NAPKINS' => ['48', '56'],
        'TOILET PAPER' => ['48'],
        'PAPER TOWEL' => ['48'],
        'MED.SYRINGE' => ['90'],
        'CATHETER' => ['90'],
        'ENEMA' => ['90'],
        'TOWEL' => ['63', '60', '52', '61', '62'],
        'WATER' => ['22', '99', '28'],
    ];

    public function handle(CatalogRetriever $retriever): int
    {
        ini_set('memory_limit', '1024M');
        $models = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('models')))));
        $per = max(1, (int) $this->option('per'));

        $cloud = OpenRouterClient::fromConfig();
        $clientFor = fn (string $m) => $cloud;
        $slugFor = fn (string $m) => $m;

        $sample = $this->sample($per);
        $this->info(count($sample).' items; candidates retrieved once, re-ranked by '.count($models).' models');
        $this->line('Models: '.implode(', ', $models));
        $this->newLine();

        // 1) Retrieve a shared candidate set per item (expand + hybrid retrieve).
        $this->line('==> retrieving candidates ...');
        $candsByIdx = [];
        $bar = $this->output->createProgressBar(count($sample));
        foreach ($sample as $idx => [$group, $item]) {
            $retrievalText = $this->expandForRetrieval($cloud, $item);
            $candsByIdx[$idx] = $retriever->candidates($retrievalText, (int) config('classify.candidates'));
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        // 2) Re-rank the same set with each model.
        $results = [];
        $tokens = array_fill_keys($models, 0);
        $millis = array_fill_keys($models, 0.0);
        foreach ($models as $model) {
            $this->line("==> {$model}");
            $bar = $this->output->createProgressBar(count($sample));
            foreach ($sample as $idx => [$group, $item]) {
                [$code, $conf, $tok, $ms, $err] = $this->rerank($clientFor($model), $slugFor($model), $item, $candsByIdx[$idx]);
                $chapter = $code ? substr($code, 0, 2) : '—';
                $match = $code ? collect($candsByIdx[$idx])->firstWhere('code', $code) : null;
                $sim = $match->semantic_sim ?? null;
                $auto = $conf >= (float) config('classify.auto_confirm')
                    && $sim !== null && $sim >= (float) config('classify.min_semantic');
                $results[$idx][$model] = [
                    'chapter' => $chapter,
                    'plausible' => $this->plausible($group, $chapter),
                    'auto' => $auto,
                    'error' => $err,
                ];
                $tokens[$model] += $tok;
                $millis[$model] += $ms;
                $bar->advance();
            }
            $bar->finish();
            $this->newLine(2);
        }

        $this->renderPerItem($sample, $models, $results);
        $this->renderSummary($sample, $models, $results, $tokens, $millis);

        return self::SUCCESS;
    }

    /** Copy of ClassifierService::expandForRetrieval (kept self-contained). */
    private function expandForRetrieval(OpenRouterClient $llm, string $text): string
    {
        $hints = $this->trapHints($text);
        try {
            $r = $llm->jsonWithUsage([
                ['role' => 'system', 'content' => $this->expandPrompt()],
                ['role' => 'user', 'content' => $text],
            ]);
            $desc = trim((string) ($r['data']['description'] ?? ''));

            return trim($desc.' '.$hints.' '.$text);
        } catch (Throwable) {
            return trim($hints.' '.$text);
        }
    }

    private function trapHints(string $text): string
    {
        $low = mb_strtolower($text);
        $hints = [];
        foreach ((array) config('classify.traps', []) as $needle => $hint) {
            if (mb_stripos($low, mb_strtolower((string) $needle)) !== false) {
                $hints[] = $hint;
            }
        }

        return implode(' ', array_values(array_unique($hints)));
    }

    /**
     * @param array<int, object> $candidates
     * @return array{0:?string,1:float,2:int,3:float,4:?string} [code, confidence, tokens, ms, error]
     */
    private function rerank(OpenRouterClient $llm, string $model, string $text, array $candidates): array
    {
        $lines = [];
        foreach (array_values($candidates) as $i => $c) {
            $lines[] = ($i + 1).". code={$c->code} [{$c->kind}] ".mb_substr($c->name, 0, 180);
        }
        $list = implode("\n", $lines);

        $t0 = microtime(true);
        try {
            $r = $llm->jsonWithUsage([
                ['role' => 'system', 'content' => $this->prompt()],
                ['role' => 'user', 'content' => "ITEM: {$text}\n\nCANDIDATES:\n{$list}"],
            ], ['model' => $model]);
            $ms = (microtime(true) - $t0) * 1000;
            $d = $r['data'];
            $code = isset($d['code']) && $d['code'] !== null && $d['code'] !== '' ? (string) $d['code'] : null;

            return [$code, (float) ($d['confidence'] ?? 0), $r['usage']['total_tokens'] ?? 0, $ms, null];
        } catch (Throwable $e) {
            return [null, 0.0, 0, (microtime(true) - $t0) * 1000, substr($e->getMessage(), 0, 80)];
        }
    }

    /** @return array<int, array{0:string,1:string}> */
    private function sample(int $per): array
    {
        $path = (string) $this->option('file');
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $rows = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);
        array_shift($rows);

        $byGroup = [];
        foreach ($rows as $r) {
            $name = trim((string) ($r[0] ?? ''));
            $group = trim((string) ($r[1] ?? ''));
            if ($name !== '' && $group !== '') {
                $byGroup[$group][] = $name;
            }
        }

        $sample = [];
        foreach ($byGroup as $group => $items) {
            $n = min($per, count($items));
            $step = max(1, intdiv(count($items), $n));
            for ($i = 0, $picked = 0; $picked < $n && $i < count($items); $i += $step, $picked++) {
                $sample[] = [$group, $items[$i]];
            }
        }

        return $sample;
    }

    private function plausible(string $group, string $chapter): ?bool
    {
        $up = mb_strtoupper($group);
        foreach ($this->expected as $key => $chapters) {
            if (str_contains($up, $key)) {
                return in_array($chapter, $chapters, true);
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0:string,1:string}> $sample
     * @param array<int, string> $models
     * @param array<int, array<string, mixed>> $results
     */
    private function renderPerItem(array $sample, array $models, array $results): void
    {
        $short = fn ($m) => mb_substr(str_contains($m, '/') ? explode('/', $m, 2)[1] : $m, 0, 14);
        $head = ['Group', 'Item'];
        foreach ($models as $m) {
            $head[] = $short($m);
        }

        $rows = [];
        foreach ($sample as $idx => [$group, $item]) {
            $cells = [mb_substr($group, 0, 12), mb_substr($item, 0, 28)];
            $chapters = [];
            foreach ($models as $m) {
                $c = $results[$idx][$m];
                $mark = $c['plausible'] === null ? '·' : ($c['plausible'] ? '✓' : '✗');
                $cells[] = $c['error'] ? 'ERR' : $c['chapter'].$mark;
                if ($c['plausible'] !== null) {
                    $chapters[] = $c['chapter'];
                }
            }
            if (count(array_unique($chapters)) > 1) {
                $cells[1] = '» '.$cells[1];
            }
            $rows[] = $cells;
        }

        $this->table($head, $rows);
        $this->line('  ✓ plausible · ✗ implausible · · not scored · »=models disagree');
    }

    /**
     * @param array<int, array{0:string,1:string}> $sample
     * @param array<int, string> $models
     * @param array<int, array<string, mixed>> $results
     * @param array<string, int> $tokens
     * @param array<string, float> $millis
     */
    private function renderSummary(array $sample, array $models, array $results, array $tokens, array $millis): void
    {
        $this->newLine();
        $this->info('Summary:');
        $rows = [];
        foreach ($models as $m) {
            $scored = $good = $auto = $err = 0;
            foreach (array_keys($sample) as $idx) {
                $c = $results[$idx][$m];
                $err += $c['error'] ? 1 : 0;
                $auto += $c['auto'] ? 1 : 0;
                if ($c['plausible'] !== null) {
                    $scored++;
                    $good += $c['plausible'] ? 1 : 0;
                }
            }
            $pct = $scored ? round(100 * $good / $scored).'%' : 'n/a';
            $avg = count($sample) ? round($millis[$m] / count($sample)) : 0;
            $rows[] = [$m, "{$good}/{$scored} ({$pct})", $auto, $err, number_format($tokens[$m], 0, '.', ' '), $avg.' ms'];
        }
        $this->table(['Model', 'Plausible', 'Auto-conf', 'Errors', 'Tokens', 'Avg/item'], $rows);
    }

    private function expandPrompt(): string
    {
        return <<<'PROMPT'
        You normalize a noisy e-invoice line item into a canonical product or
        service description for catalogue lookup. The item is usually Azerbaijani
        and may contain brand names, article numbers, sizes and packaging.

        Output what the item fundamentally IS — its MAIN product noun + purpose —
        in 2-6 words IN AZERBAIJANI. Drop brand names, article numbers and sizes.

        Important:
        - Return the HEAD product, not an ingredient, flavour, sauce or material.
          "fruit cake" -> cake; "fish in tomato sauce" -> canned fish (not sauce).
        - Resolve compound words by their WHOLE meaning, not a sub-word.
          "çay dəsmalı" is a tea TOWEL -> "mətbəx dəsmalı" (NOT tea/çay).
          "qrilyaj" is a grillage SWEET -> "şirniyyat" (NOT a grill/stove).
        - Expand obvious abbreviations: "cath" -> "kateter".

        Respond with strict JSON only: {"description": "..."}
        PROMPT;
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
        You are a customs classification expert for Azerbaijan's XİF MN nomenclature
        (the national TN VED / Harmonized System). You receive a product or service
        description (usually in Azerbaijani) and a list of candidate 10-digit codes
        retrieved from the official registry.

        Pick the SINGLE best matching code from the candidates.

        Rules:
        - Codes that start with "99" are SERVICES; all other codes are GOODS.
        - Choose only from the provided candidates. Do not invent codes.
        - Classify by what the item IS (its function / purpose), NOT merely by the
          material it is made of.
        - Classify by the HEAD product — not by an ingredient, flavour, sauce,
          packaging, brand or a sub-word of a compound name.
        - Prefer the most specific code that fits the item's actual purpose.
        - If none of the candidates is a reasonable match, set "code" to null.
        - Calibrate "confidence" (0..1) honestly: use > 0.85 only when a candidate
          clearly and specifically matches the item; if you can only find a generic
          or material-based fallback, keep confidence below 0.7.

        Respond with a strict JSON object only:
        {"kind":"good|service","code":"<chosen code or null>","confidence":0.0,"reason":"<short justification>"}
        PROMPT;
    }
}
