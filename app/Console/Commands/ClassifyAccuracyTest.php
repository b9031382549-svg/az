<?php

namespace App\Console\Commands;

use App\Models\ClassificationItem;
use App\Models\GoldLabel;
use App\Services\Classify\Consensus;
use App\Services\Classify\Mechanisms\BrokerDescentMechanism;
use App\Services\Classify\Mechanisms\DirectLlmMechanism;
use App\Services\Classify\Mechanisms\VectorMechanism;
use App\Services\Classify\SearchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Per-mechanism accuracy test against the Fedor gold (4-digit HS heading + service
 * flag). Runs each tool INDEPENDENTLY on the same names, compares its answer to
 * gold, and reports how many were right/wrong per tool.
 *
 * NOT a live-flow simulation: mechanisms are called directly (no queue, no answer
 * cache), and 'search' is force-run on every item (in prod it only fires on
 * conflict) so it gets its own accuracy number. The 'ensemble' column reproduces
 * what the deployed system actually outputs: consensus 2-of-3 heading, else the
 * search resolver's confident heading.
 *
 * Models are overridden at runtime to match PROD (DeepSeek), not the local dev
 * defaults, so the numbers reflect the deployed tools.
 */
class ClassifyAccuracyTest extends Command
{
    protected $signature = 'classify:accuracy-test
        {--limit=0 : Cap the number of names (0 = all in the file)}
        {--offset=0 : Skip the first N names (slicing)}
        {--methods=vector,broker,direct,search : Which tools to run}
        {--file= : xlsx of names (default fedor_test_100.xlsx); gold looked up in gold_labels (fedor)}
        {--csv= : CSV with inline gold — columns name,hs6,full_code (e.g. research-data/test-100.csv). Overrides --file}
        {--out=results.jsonl : Results filename under storage/app/accuracy (use a distinct name per dataset)}
        {--tag=acc-test : Batch tag for scratch ClassificationItems}
        {--fresh : Discard prior progress + scratch items and start over}
        {--score-only : Skip running; just re-score the existing results file}';

    protected $description = 'Accuracy of each classifier tool (vector/broker/direct/search) vs a gold reference (Fedor headings, or an inline-gold CSV).';

    /** Where per-item results accumulate (one JSON object per line). */
    private string $resultsPath;

    /** name → gold (when running from an inline-gold CSV); null in Fedor/gold_labels mode. */
    private ?array $inlineGold = null;

    public function handle(): int
    {
        $out = basename((string) ($this->option('out') ?: 'results.jsonl'));
        $this->resultsPath = storage_path('app/accuracy/'.$out);
        File::ensureDirectoryExists(dirname($this->resultsPath));

        // Inline-gold CSV mode (e.g. EU-EBTI test set): gold lives on each row.
        if ($this->option('csv')) {
            $this->inlineGold = $this->loadCsvGold((string) $this->option('csv'));
        }

        $methods = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('methods')))));

        if ($this->option('score-only')) {
            $this->report($methods);

            return self::SUCCESS;
        }

        $this->applyProdModels();

        if ($this->option('fresh')) {
            File::delete($this->resultsPath);
            ClassificationItem::where('batch', $this->option('tag'))->delete();
            $this->warn('Fresh run: cleared prior results + scratch items.');
        }

        $names = $this->loadNames();
        $done = $this->doneNames();
        $this->info(sprintf('Loaded %d names; %d already done; running %d.',
            count($names), count($done), count(array_diff($names, $done))));
        $this->line('Methods: '.implode(', ', $methods));
        $this->line('Models  → '.$this->modelSummary());
        $this->newLine();

        $i = 0;
        $total = count($names);
        foreach ($names as $name) {
            $i++;
            if (in_array($name, $done, true)) {
                continue;
            }

            $gold = $this->goldFor($name);
            if ($gold === null) {
                $this->warn("[$i/$total] NO GOLD, skipped: ".$this->short($name));

                continue;
            }

            $row = $this->runOne($name, $gold, $methods, $i, $total);
            File::append($this->resultsPath, json_encode($row, JSON_UNESCAPED_UNICODE).PHP_EOL);
        }

        $this->newLine();
        $this->report($methods);

        return self::SUCCESS;
    }

    /**
     * Run every requested tool on ONE name and return a flat result record.
     *
     * @param  array{heading: ?string, is_service: bool}  $gold
     * @param  array<int, string>  $methods
     * @return array<string, mixed>
     */
    private function runOne(string $name, array $gold, array $methods, int $i, int $total): array
    {
        // Fresh scratch item each run so a resumed/partial item is clean.
        $hash = hash('sha256', $name);
        ClassificationItem::where('batch', $this->option('tag'))->where('source_hash', $hash)->delete();
        $item = ClassificationItem::create([
            'batch' => (string) $this->option('tag'),
            'source_text' => $name,
            'source_hash' => $hash,
            'resolution' => 'conflict', // lets the search resolver's confident-flip path run
        ]);

        $rec = [
            'name' => $name,
            'gold_heading' => $gold['heading'],
            'gold_hs6' => $gold['hs6'] ?? null,
            'gold_full' => $gold['full'] ?? null,
            'gold_service' => $gold['is_service'],
        ];

        // 1-3) The three independent mechanisms (persist each as a result row so
        //      Consensus + the search resolver can read them).
        $classes = [
            'vector' => VectorMechanism::class,
            'broker' => BrokerDescentMechanism::class,
            'direct' => DirectLlmMechanism::class,
        ];
        foreach ($classes as $key => $class) {
            if (! in_array($key, $methods, true)) {
                continue;
            }
            [$code, $kind, $status] = $this->safeMechanism($class, $name);
            $item->results()->create([
                'mechanism' => $key,
                'matched_code' => $code,
                'kind' => $kind,
                'status' => $status,
                'confidence' => null,
            ]);
            $rec[$key] = $this->pred($code, $kind);
            $rec[$key.'_ok'] = $this->isCorrect($rec[$key], $gold);
        }

        // 4) Search resolver — its own accuracy number; independent of the other
        //    mechanisms (it only reads the item text). Force-run on every item.
        $searchPred = ['heading' => null, 'service' => false, 'conf' => null];
        if (in_array('search', $methods, true)) {
            try {
                app(SearchResolverService::class)->resolve($item);
                $sr = $item->results()->where('mechanism', 'search')->first();
                if ($sr) {
                    $searchPred = [
                        'heading' => $sr->matched_code, // already 4-digit or "99"
                        'service' => $this->serviceish($sr->kind, $sr->matched_code),
                        'conf' => $sr->confidence,
                    ];
                }
            } catch (Throwable $e) {
                $searchPred['error'] = $this->short($e->getMessage(), 120);
            }
            $rec['search'] = ['heading' => $this->head($searchPred['heading']), 'hs6' => null, 'code' => $searchPred['heading'], 'service' => $searchPred['service']];
            $rec['search_ok'] = $this->isCorrect($rec['search'], $gold);
        }

        // Ensemble — the deployed system's answer. Only meaningful when all three
        // offline mechanisms ran: consensus 2-of-3, else the search resolver's
        // CONFIDENT heading (the live flow flips to 'ai_resolved' only at >= min_conf).
        if (count(array_intersect(['vector', 'broker', 'direct'], $methods)) === 3) {
            $results = $item->results()->whereIn('mechanism', ['vector', 'broker', 'direct'])->get();
            $consensus = app(Consensus::class)->resolve($results);
            $minConf = (float) config('classify.search_resolver.min_confidence', 0.8);
            $ens = ['heading' => null, 'hs6' => null, 'code' => null, 'service' => false];
            if (($consensus['resolution'] ?? null) === 'agreed') {
                $ens = ['heading' => $this->head($consensus['final_code']), 'hs6' => null, 'code' => $consensus['final_code'], 'service' => $this->serviceish($consensus['kind'], $consensus['final_code'])];
            } elseif ($searchPred['heading'] !== null && (float) ($searchPred['conf'] ?? 0) >= $minConf) {
                $ens = ['heading' => $this->head($searchPred['heading']), 'hs6' => null, 'code' => $searchPred['heading'], 'service' => $searchPred['service']];
            }
            $rec['ensemble'] = $ens;
            $rec['ensemble_ok'] = $this->isCorrect($ens, $gold);
            $rec['consensus'] = $consensus['resolution'] ?? null;
        }

        $this->line(sprintf('[%d/%d] %s | gold %s%s  →  %s',
            $i, $total, str_pad($this->short($name, 42), 42),
            $gold['is_service'] ? 'SERVICE' : $gold['heading'],
            '',
            $this->line1($rec, $methods),
        ));

        return $rec;
    }

    /** Run one mechanism, swallowing failures into a no_match. @return array{0:?string,1:?string,2:string} */
    private function safeMechanism(string $class, string $name): array
    {
        try {
            $r = app($class)->classify($name);

            return [$r->matchedCode, $r->kind, $r->status];
        } catch (Throwable $e) {
            $this->warn('  '.class_basename($class).' failed: '.$this->short($e->getMessage(), 120));

            return [null, null, 'error'];
        }
    }

    /** @return array{heading: ?string, hs6: ?string, code: ?string, service: bool} */
    private function pred(?string $code, ?string $kind): array
    {
        return [
            'heading' => $this->head($code),
            'hs6' => ($code !== null && mb_strlen($code) >= 6) ? mb_substr($code, 0, 6) : null,
            'code' => $code,
            'service' => $this->serviceish($kind, $code),
        ];
    }

    /** Is a prediction correct against gold? Services score on the flag; goods on the 4-digit heading. */
    private function isCorrect(array $pred, array $gold): bool
    {
        if ($gold['is_service']) {
            return $pred['service'] === true;
        }

        return $pred['service'] === false
            && $pred['heading'] !== null
            && $pred['heading'] === $gold['heading'];
    }

    private function head(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return mb_substr($code, 0, 4);
    }

    /** A service if the kind says so or the code sits in chapter/heading 99. */
    private function serviceish(?string $kind, ?string $code): bool
    {
        return $kind === 'service'
            || $kind === '99'
            || ($code !== null && str_starts_with($code, '99'));
    }

    /** @return array{heading: ?string, hs6: ?string, full: ?string, is_service: bool}|null */
    private function goldFor(string $name): ?array
    {
        if ($this->inlineGold !== null) {
            return $this->inlineGold[$name] ?? null;
        }

        $g = GoldLabel::where('source', 'fedor')->where('name_key', GoldLabel::keyFor($name))->first();
        if ($g === null) {
            return null;
        }

        return ['heading' => $g->heading, 'hs6' => null, 'full' => null, 'is_service' => (bool) $g->is_service];
    }

    /**
     * Load an inline-gold CSV (columns name,hs6,full_code) into a name → gold map.
     * The 4-digit heading is derived from full_code (fallback hs6); all rows are goods.
     *
     * @return array<string, array{heading: ?string, hs6: ?string, full: ?string, is_service: bool}>
     */
    private function loadCsvGold(string $path): array
    {
        $path = str_starts_with($path, '/') ? $path : base_path($path);
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        $idx = array_flip(array_map('trim', $header));
        $map = [];
        while (($r = fgetcsv($fh)) !== false) {
            $name = trim((string) ($r[$idx['name']] ?? ''));
            if ($name === '') {
                continue;
            }
            $full = trim((string) ($r[$idx['full_code']] ?? ''));
            $hs6 = trim((string) ($r[$idx['hs6']] ?? ''));
            $map[$name] = [
                'heading' => mb_substr($full !== '' ? $full : $hs6, 0, 4) ?: null,
                'hs6' => $hs6 !== '' ? mb_substr($hs6, 0, 6) : null,
                'full' => $full !== '' ? $full : null,
                'is_service' => false,
            ];
        }
        fclose($fh);

        return $map;
    }

    /** @return array<int, string> */
    private function loadNames(): array
    {
        if ($this->inlineGold !== null) {
            $names = array_keys($this->inlineGold); // preserves CSV order
        } else {
            $path = (string) ($this->option('file') ?: base_path('fedor_test_100.xlsx'));
            $rows = IOFactory::load($path)->getActiveSheet()->toArray();
            $names = [];
            for ($r = 1; $r < count($rows); $r++) { // row 0 = header
                $n = trim((string) ($rows[$r][0] ?? ''));
                if ($n !== '') {
                    $names[] = $n;
                }
            }
        }
        $offset = (int) $this->option('offset');
        $limit = (int) $this->option('limit');
        $names = array_slice($names, $offset);

        return $limit > 0 ? array_slice($names, 0, $limit) : $names;
    }

    /** @return array<int, string> names already recorded in the results file */
    private function doneNames(): array
    {
        if (! File::exists($this->resultsPath)) {
            return [];
        }
        $names = [];
        foreach (file($this->resultsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $o = json_decode($line, true);
            if (isset($o['name'])) {
                $names[] = $o['name'];
            }
        }

        return $names;
    }

    /** Match prod's model wiring (DeepSeek), overriding the local dev defaults. */
    private function applyProdModels(): void
    {
        config([
            'classify.mechanisms.enabled' => ['vector', 'broker', 'direct'],
            'classify.broker.model' => 'deepseek/deepseek-chat',
            'classify.broker.brief_model' => 'deepseek/deepseek-chat',
            'classify.broker.fact_model' => 'deepseek/deepseek-chat',
            'classify.expand_model' => 'deepseek/deepseek-chat',
            'services.openrouter.classify_model' => 'deepseek/deepseek-chat',
            'services.openrouter.classify_model_tier1' => 'qwen/qwen-2.5-7b-instruct',
            'classify.direct.model' => 'openai/gpt-oss-120b',
            'classify.search_resolver.enabled' => true,
            'classify.search_resolver.model' => 'deepseek/deepseek-v4-flash:online',
            // We only ever compare at the 4-digit heading, so let broker + direct vote
            // at that granularity instead of chasing a full 10-digit code they can't
            // reliably produce (broker abstains after a good heading; direct can't
            // recall a national 10-digit code and abstains ~half the time).
            'classify.broker.answer_granularity' => 'heading',
            'classify.direct.granularity' => 'heading',
        ]);
    }

    private function modelSummary(): string
    {
        return 'vector/broker/expand=deepseek-chat (tier1 qwen-2.5-7b), direct=gpt-oss-120b, search=deepseek-v4-flash:online · broker+direct vote 4-digit heading';
    }

    /** Compact per-item line, e.g. "V:1701✓ B:1701✓ D:1702✗ S:1701✓ | ens:1701✓". */
    private function line1(array $rec, array $methods): string
    {
        $cell = function (string $k) use ($rec) {
            if (! isset($rec[$k])) {
                return '';
            }
            $p = $rec[$k];
            $val = $p['service'] ? 'SVC' : ($p['heading'] ?? '—');
            $mark = ($rec[$k.'_ok'] ?? false) ? '✓' : '✗';

            return strtoupper($k[0]).':'.$val.$mark;
        };
        $parts = array_filter(array_map($cell, ['vector', 'broker', 'direct', 'search']));
        if (isset($rec['ensemble'])) {
            $ens = $rec['ensemble'];
            $parts[] = 'ens:'.($ens['service'] ? 'SVC' : ($ens['heading'] ?? '—')).(($rec['ensemble_ok'] ?? false) ? '✓' : '✗');
        }

        return implode(' ', $parts);
    }

    /** Read the results file and print the accuracy table + write a CSV. */
    private function report(array $methods): void
    {
        if (! File::exists($this->resultsPath)) {
            $this->error('No results file yet: '.$this->resultsPath);

            return;
        }
        $rows = [];
        foreach (file($this->resultsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $o = json_decode($line, true);
            if (is_array($o)) {
                $rows[] = $o;
            }
        }

        $cols = array_values(array_filter(['vector', 'broker', 'direct', 'search', 'ensemble'],
            fn ($c) => $c === 'ensemble' || in_array($c, $methods, true)));

        $table = [];
        foreach ($cols as $c) {
            $total = 0;
            $correct = 0;
            $answered = 0;
            foreach ($rows as $row) {
                if (! array_key_exists($c, $row)) {
                    continue;
                }
                $total++;
                $p = $row[$c];
                if ($p['service'] || $p['heading'] !== null) {
                    $answered++;
                }
                if ($row[$c.'_ok'] ?? false) {
                    $correct++;
                }
            }
            if ($total === 0) {
                continue; // method not present in this results file (e.g. vector-only run)
            }
            $wrong = $total - $correct;
            $acc = $total ? round(100 * $correct / $total, 1) : 0.0;
            $covAcc = $answered ? round(100 * $correct / $answered, 1) : 0.0;
            $table[] = [
                strtoupper($c),
                $total,
                $correct,
                $wrong,
                $total - $answered,
                $acc.'%',
                $covAcc.'%',
            ];
        }

        $src = $this->option('csv') ? basename((string) $this->option('csv')) : 'Fedor gold';
        $this->newLine();
        $this->info('ACCURACY @ 4-digit heading vs '.$src.'  —  '.count($rows).' items');
        $this->table(
            ['Tool', 'Total', 'Correct', 'Wrong', 'NoAnswer', 'Accuracy', 'Acc(answered)'],
            $table,
        );

        $csv = storage_path('app/accuracy/results.csv');
        $this->writeCsv($csv, $rows, $cols);
        $this->line('Per-item CSV: '.$csv);
        $this->line('Raw JSONL   : '.$this->resultsPath);
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function writeCsv(string $path, array $rows, array $cols): void
    {
        $fh = fopen($path, 'w');
        $header = ['name', 'gold_heading', 'gold_service', 'consensus'];
        foreach ($cols as $c) {
            $header[] = $c;
            $header[] = $c.'_ok';
        }
        fputcsv($fh, $header);
        foreach ($rows as $row) {
            $line = [
                $row['name'],
                $row['gold_heading'],
                $row['gold_service'] ? 'service' : '',
                $row['consensus'] ?? '',
            ];
            foreach ($cols as $c) {
                $p = $row[$c] ?? null;
                $line[] = $p ? ($p['service'] ? 'SERVICE' : ($p['heading'] ?? '')) : '';
                $line[] = ($row[$c.'_ok'] ?? false) ? '1' : '0';
            }
            fputcsv($fh, $line);
        }
        fclose($fh);
    }

    private function short(string $s, int $n = 60): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s));

        return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1).'…' : $s;
    }
}
