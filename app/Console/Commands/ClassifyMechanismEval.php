<?php

namespace App\Console\Commands;

use App\Services\Classify\Mechanisms\VectorMechanism;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Run the FULL vector mechanism (brief → expansion → hybrid retrieval → 2-tier LLM
 * re-rank) over a held-out set and report correct% at the 4-digit heading — the
 * SAME number as the prod Testing UI's "vector" column (run 4 = 61%, run 8 = 70%).
 *
 * Runs synchronously in one process, so `--search-path ft,public` makes every
 * retrieval query hit the fine-tuned ft.catalog / ft.precedents on the SAME
 * connection (no retriever change). Point OLLAMA_URL at the FT embed server so the
 * mechanism's dynamic queries are embedded by the fine-tuned model. `--no-brief`
 * flips use_brief_query off to test dropping the normalization step.
 */
class ClassifyMechanismEval extends Command
{
    protected $signature = 'classify:mechanism-eval
        {--file=research-data/finetune/gold-split/test.jsonl : {name,gold} JSONL}
        {--search-path= : e.g. "ft,public" to run against fine-tuned tables (empty = stock)}
        {--no-brief : disable use_brief_query (test dropping normalization)}
        {--limit=0 : cap items (applied AFTER offset)}
        {--offset=0 : skip the first N items (for parallel sharding)}
        {--out=storage/app/mechanism-eval.jsonl : per-item output}';

    protected $description = 'Run the full vector mechanism over a test set; report 4-digit correct% (comparable to prod run 4/8).';

    public function handle(): int
    {
        $path = base_path((string) $this->option('file'));
        if (! is_file($path)) {
            $this->error("Missing: {$path}");

            return self::FAILURE;
        }

        if ($sp = trim((string) $this->option('search-path'))) {
            if (! preg_match('/^[a-z0-9_, ]+$/i', $sp)) {
                $this->error('bad search-path');

                return self::FAILURE;
            }
            DB::statement("SET search_path TO {$sp}");
            $this->info("search_path = {$sp}");
        }

        $useBrief = ! $this->option('no-brief');
        config(['classify.vector.use_brief_query' => $useBrief]);
        $this->info('use_brief_query = '.($useBrief ? 'true' : 'false'));

        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');
        $all = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if (isset($r['name'], $r['gold'])) {
                $all[] = $r;
            }
        }
        $items = array_slice($all, $offset, $limit > 0 ? $limit : null);

        /** @var VectorMechanism $mech */
        $mech = app(VectorMechanism::class);
        $out = fopen(base_path((string) $this->option('out')), 'w');

        $total = count($items);
        $answered = 0;
        $correct = 0;
        $errors = 0;
        $i = 0;
        foreach ($items as $item) {
            $gold = (string) $item['gold'];
            $status = null;
            $err = null;
            try {
                $res = $mech->classify((string) $item['name']);
                $code = $res->matchedCode;
                $status = $res->status;
            } catch (\Throwable $e) {
                $code = null;
                $err = $e->getMessage();
            }
            $head = $code !== null ? substr((string) $code, 0, 4) : null;
            $ok = $head !== null && $head === $gold;
            if ($code !== null) {
                $answered++;
            }
            if ($ok) {
                $correct++;
            }
            if ($err !== null) {
                $errors++;
            }

            fwrite($out, json_encode([
                'name' => $item['name'], 'gold' => $gold, 'code' => $code, 'head' => $head, 'ok' => $ok,
                'status' => $status, 'err' => $err,
            ], JSON_UNESCAPED_UNICODE)."\n");

            if (++$i % 25 === 0) {
                $this->output->write(sprintf("\r  %d/%d  correct=%d (%.1f%% of all)   ", $i, $total, $correct, 100 * $correct / $i));
            }
        }
        fclose($out);
        $this->newLine(2);

        $this->table(['metric', 'value'], [
            ['total', $total],
            ['answered', $answered],
            ['errors (LLM/exception)', $errors],
            ['correct (4-digit)', $correct],
            ['correct / total', sprintf('%.1f%%', $total ? 100 * $correct / $total : 0)],
            ['correct / answered', sprintf('%.1f%%', $answered ? 100 * $correct / $answered : 0)],
        ]);

        return self::SUCCESS;
    }
}
