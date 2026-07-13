<?php

// Verify the integrated candidates() heading_fusion flag reproduces the +12pp gain.
// query = cached brief identity; recall@24 (correct heading among top-24 codes),
// flag OFF vs ON. Read-only, no new LLM. Resumable.

use App\Services\Classify\CatalogRetriever;
use App\Services\Classify\ProductBriefService;
use App\Services\Embeddings\OllamaEmbedder;
use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$N = (int) (getenv('N') ?: 150);
$K = 24;
$OUT = getenv('OUT') ?: '/var/www/html/experiments/heading-clf/verify_results.jsonl';
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

$briefs = app(ProductBriefService::class);
$emb = OllamaEmbedder::fromConfig();
config(['classify.precedents.enabled' => true, 'classify.precedents.top_k' => 40, 'classify.precedents.per_heading' => 2]);
config(['classify.retrieval.heading_fusion' => false]);
$retOff = new CatalogRetriever($emb);
config(['classify.retrieval.heading_fusion' => true]);
$retOn = new CatalogRetriever($emb);

$hit = function ($ret, $q, $h) use ($K) {
    if ($q === '') {
        return 0;
    }
    $i = 0;
    foreach ($ret->candidates($q, $K) as $c) {
        if ($i++ >= $K) {
            break;
        }
        if (substr((string) $c->code, 0, 4) === $h) {
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
    fwrite($fh, json_encode(['name' => $name, 'off' => $hit($retOff, $q, $h), 'on' => $hit($retOn, $q, $h)], JSON_UNESCAPED_UNICODE)."\n");
    fflush($fh);
    if ($i % 10 === 0) {
        fwrite(STDERR, "$i/$n\n");
    }
}
fclose($fh);

$off = $on = $cnt = 0;
foreach (file($OUT) as $l) {
    $o = json_decode($l, true);
    if (! is_array($o)) {
        continue;
    }
    $cnt++;
    $off += (int) ($o['off'] ?? 0);
    $on += (int) ($o['on'] ?? 0);
}
printf("\n=== candidates() heading_fusion (n=%d, recall@%d) ===\n", $cnt, $K);
printf("OFF (code-level):    %.1f%%\n", 100 * $off / max(1, $cnt));
printf("ON  (heading-level): %.1f%%   (Δ %+.1f pp)\n", 100 * $on / max(1, $cnt), 100 * ($on - $off) / max(1, $cnt));
echo "done\n";
