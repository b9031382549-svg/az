<?php

namespace App\Console\Commands;

use App\Services\Classify\CatalogRetriever;
use App\Services\Classify\ClassifierService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * "Vector-first re-rank" eval: retrieve the PURE FT-vector top-N (no expansion,
 * fusion, lexical or precedents), hand that clean short list to the SAME two-tier
 * re-rank LLM, and let it pick. Measures whether a strong embedder + a light picker
 * realises the recall@N ceiling (v2-FT: top-10 heading recall ≈ 91%) — vs the heavy
 * mechanism that drags the same vector down to 66.6%.
 *
 * Run with search_path=ft,public + OLLAMA_EMBED_MODEL=bge-ft-v2 so retrieval hits
 * the fine-tuned catalog and the fine-tuned embedder.
 */
class ClassifyVecfirstEval extends Command
{
    protected $signature = 'classify:vecfirst-eval
        {--file=research-data/finetune/gold-split/test.jsonl : {name,gold} JSONL}
        {--search-path= : e.g. "ft,public"}
        {--topn=10 : pure-vector candidates handed to the re-rank}
        {--limit=0 : cap items (after offset)}
        {--offset=0 : shard offset}
        {--out=storage/app/vecfirst-eval.jsonl : per-item output}';

    protected $description = 'Vector-first re-rank: pure FT-vector top-N → LLM picks. Reports 4-digit correct%.';

    public function handle(CatalogRetriever $retriever, ClassifierService $classifier): int
    {
        if ($sp = trim((string) $this->option('search-path'))) {
            if (! preg_match('/^[a-z0-9_, ]+$/i', $sp)) {
                $this->error('bad search-path');

                return self::FAILURE;
            }
            DB::statement("SET search_path TO {$sp}");
        }
        $topn = max(1, (int) $this->option('topn'));

        $all = [];
        foreach (file(base_path((string) $this->option('file')), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if (isset($r['name'], $r['gold'])) {
                $all[] = $r;
            }
        }
        $limit = (int) $this->option('limit');
        $items = array_slice($all, (int) $this->option('offset'), $limit > 0 ? $limit : null);

        $out = fopen(base_path((string) $this->option('out')), 'w');
        $total = count($items);
        $answered = 0;
        $correct = 0;
        $errors = 0;
        $recallInList = 0; // gold heading present in the top-N handed to the LLM (the ceiling)
        $i = 0;

        foreach ($items as $item) {
            $gold = (string) $item['gold'];
            $code = null;
            $inList = false;
            try {
                $cands = $retriever->semanticCandidates((string) $item['name'], $topn);
                $inList = collect($cands)->contains(fn ($c) => substr((string) $c->code, 0, 4) === $gold);
                $pick = $classifier->pickFromCandidates((string) $item['name'], $cands);
                $code = $pick['code'] ?? null;
            } catch (\Throwable $e) {
                $errors++;
            }
            $head = $code !== null ? substr((string) $code, 0, 4) : null;
            $ok = $head !== null && $head === $gold;
            $answered += $code !== null ? 1 : 0;
            $correct += $ok ? 1 : 0;
            $recallInList += $inList ? 1 : 0;

            fwrite($out, json_encode(['gold' => $gold, 'code' => $code, 'head' => $head, 'ok' => $ok, 'in_list' => $inList], JSON_UNESCAPED_UNICODE)."\n");
            if (++$i % 25 === 0) {
                $this->output->write(sprintf("\r  %d/%d correct=%d", $i, $total, $correct));
            }
        }
        fclose($out);
        $this->newLine(2);

        $this->table(['metric', 'value'], [
            ['total', $total],
            ['answered', $answered],
            ['errors', $errors],
            ['gold in top-N list (ceiling)', sprintf('%.1f%%', $total ? 100 * $recallInList / $total : 0)],
            ['correct (4-digit)', $correct],
            ['correct / total', sprintf('%.1f%%', $total ? 100 * $correct / $total : 0)],
            ['correct / answered', sprintf('%.1f%%', $answered ? 100 * $correct / $answered : 0)],
        ]);

        return self::SUCCESS;
    }
}
