<?php

// Fusion A/B (retrieval-internal, no LLM except the cached brief, no GPU).
// V0 = current code-level RRF (candidates()); V2 = code-RRF with SMART precedent
// expansion (winning headings -> catalog codes nearest the query); V3 = HEADING-level
// RRF (sources mapped to 4-digit headings; precedents vote heading directly).
// Metric: correct 4-digit heading in top-K. Query = brief identity (cached). Resumable.

use App\Services\Classify\CatalogRetriever;
use App\Services\Classify\ProductBriefService;
use App\Services\Embeddings\OllamaEmbedder;
use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$N = (int) (getenv('N') ?: 150);
$PER = 50;
$TOPK_PREC = 40;
$OUT = getenv('OUT') ?: '/var/www/html/experiments/heading-clf/fusion_results.jsonl';
$Ks = [5, 10, 24];

$rows = DB::table('gold_labels')->where('source', 'fedor')->whereNotNull('heading')
    ->orderBy('id')->limit($N)->get(['name', 'heading'])->all();
$n = count($rows);

$done = [];
if (is_file($OUT)) {
    foreach (file($OUT) as $l) {
        $o = json_decode($l, true);
        if (isset($o['name'])) {
            $done[$o['name']] = true;
        }
    }
}
fwrite(STDERR, "items: {$n}, done: ".count($done)."\n");

$briefs = app(ProductBriefService::class);
$emb = OllamaEmbedder::fromConfig();
config(['classify.precedents.enabled' => true, 'classify.precedents.top_k' => 40, 'classify.precedents.per_heading' => 2]);
$ret = app(CatalogRetriever::class);

$priv = function ($m, array $a) use ($ret) {
    return Closure::bind(fn () => $this->$m(...$a), $ret, CatalogRetriever::class)();
};

$codes = fn ($rws) => array_map(fn ($r) => (string) $r->code, array_values($rws));
$heads = function ($rws) {
    $out = [];
    $seen = [];
    foreach ($rws as $r) {
        $h = substr((string) $r->code, 0, 4);
        if ($h === '' || isset($seen[$h])) {
            continue;
        }
        $seen[$h] = true;
        $out[] = $h;
    }

    return $out;
};
$rrf = function ($lists, $k = 60) {
    $s = [];
    foreach ($lists as $keys) {
        $seen = [];
        $rank = 0;
        foreach ($keys as $key) {
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $s[$key] = ($s[$key] ?? 0) + 1 / ($k + $rank + 1);
            $rank++;
        }
    }
    arsort($s);

    return array_keys($s);
};
$hitCode = function ($ranked, $h, $K) {
    $i = 0;
    foreach ($ranked as $c) {
        if ($i++ >= $K) {
            break;
        }
        if (substr((string) $c, 0, 4) === $h) {
            return 1;
        }
    }

    return 0;
};
$hitHead = function ($ranked, $h, $K) {
    $i = 0;
    foreach ($ranked as $x) {
        if ($i++ >= $K) {
            break;
        }
        if ((string) $x === $h) {
            return 1;
        }
    }

    return 0;
};

$fh = fopen($OUT, 'a');
$i = 0;
foreach ($rows as $g) {
    $name = trim((string) $g->name);
    $h = (string) $g->heading;
    $i++;
    if (isset($done[$name])) {
        continue;
    }

    try {
        $b = $briefs->brief($name);
        $q = is_array($b) && ($b['identity'] ?? '') !== '' ? $b['identity'] : $name;
    } catch (\Throwable $e) {
        $q = $name;
    }
    $qn = trim((string) preg_replace('/\b\S*\d\S*\b/u', '', $q)) ?: $q; // drop digit tokens
    $vec = OllamaEmbedder::toSqlVector($emb->embedOne($qn));

    $sem = $priv('semantic', [$vec, $PER, '', []]);
    $lex = $priv('lexical', [$q, $PER, '', []]);

    // precedent 4-digit heading vote
    $hits = DB::select("SELECT hs6 FROM precedents WHERE embedding IS NOT NULL ORDER BY embedding <=> ?::vector LIMIT {$TOPK_PREC}", [$vec]);
    $vote = [];
    foreach (array_values($hits) as $r => $row) {
        $hh = substr((string) $row->hs6, 0, 4);
        $vote[$hh] = ($vote[$hh] ?? 0) + 1 / (60 + $r + 1);
    }
    arsort($vote);
    $precHeads = array_keys($vote);

    // smart precedent code expansion: top headings -> catalog codes nearest the query
    $smart = [];
    foreach (array_slice($precHeads, 0, 15) as $hh) {
        foreach (DB::select('SELECT code FROM catalog WHERE position=? AND embedding IS NOT NULL ORDER BY embedding <=> ?::vector LIMIT 2', [$hh, $vec]) as $r) {
            $smart[] = (string) $r->code;
        }
    }

    // current-style arbitrary precedent code expansion (first codes by number)
    $arb = [];
    foreach (array_slice($precHeads, 0, 15) as $hh) {
        foreach (DB::select('SELECT code FROM catalog WHERE position=? ORDER BY code LIMIT 2', [$hh]) as $r) {
            $arb[] = (string) $r->code;
        }
    }

    $rec = ['name' => $name, 'heading' => $h];
    foreach ($Ks as $K) {
        $rec["v0_$K"] = $hitCode($rrf([$codes($sem), $codes($lex), $arb]), $h, $K);       // baseline-style
        $rec["v2_$K"] = $hitCode($rrf([$codes($sem), $codes($lex), $smart]), $h, $K);      // smart expansion
        $rec["v3_$K"] = $hitHead($rrf([$heads($sem), $heads($lex), $precHeads]), $h, $K);   // heading-level
    }
    fwrite($fh, json_encode($rec, JSON_UNESCAPED_UNICODE)."\n");
    fflush($fh);
    if ($i % 10 === 0) {
        fwrite(STDERR, "$i/$n\n");
    }
}
fclose($fh);

// aggregate
$sum = [];
$cnt = 0;
foreach (file($OUT) as $l) {
    $o = json_decode($l, true);
    if (! is_array($o)) {
        continue;
    }
    $cnt++;
    foreach ($o as $kk => $vv) {
        if (preg_match('/^v\d_\d+$/', $kk)) {
            $sum[$kk] = ($sum[$kk] ?? 0) + (int) $vv;
        }
    }
}
echo "\n=== FUSION recall@K  (identity query, n={$cnt}) ===\n";
printf("%-22s %7s %7s %7s\n", '', '@5', '@10', '@24');
$label = ['v0' => 'V0 baseline (code)', 'v2' => 'V2 smart-expand (code)', 'v3' => 'V3 HEADING-level'];
foreach (['v0', 'v2', 'v3'] as $v) {
    printf("%-22s %6.1f%% %6.1f%% %6.1f%%\n", $label[$v],
        100 * ($sum["{$v}_5"] ?? 0) / max(1, $cnt),
        100 * ($sum["{$v}_10"] ?? 0) / max(1, $cnt),
        100 * ($sum["{$v}_24"] ?? 0) / max(1, $cnt));
}
echo "done\n";
