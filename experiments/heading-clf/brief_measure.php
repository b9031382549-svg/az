<?php

// Measure the vector-flow retrieval recall with vs without the product brief
// (brief only — expand disabled). No broker/direct/rerank. precedents ON (prod config).
// Read-only. Cost: N brief LLM calls (gpt-4o).

use App\Services\Classify\CatalogRetriever;
use App\Services\Classify\ClassifierService;
use App\Services\Classify\ProductBriefService;
use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['classify.expand_query' => false]); // brief only, no expand LLM call
config(['classify.precedents.enabled' => true, 'classify.precedents.top_k' => 40, 'classify.precedents.per_heading' => 2]);

$N = (int) (getenv('N') ?: 150);
$rows = DB::table('gold_labels')->where('source', 'fedor')->whereNotNull('heading')
    ->orderBy('id')->limit($N)->get(['name', 'heading'])->all();
$n = count($rows);
fwrite(STDERR, "items: {$n}\n");

$classifier = app(ClassifierService::class);
$briefs = app(ProductBriefService::class);
$retriever = app(CatalogRetriever::class);
$K = 24;

// call the private expandForRetrieval() -> queries (expand LLM is off, so no call)
$expand = Closure::bind(function ($text, $identity) {
    return $this->expandForRetrieval($text, $identity)[0];
}, $classifier, ClassifierService::class);

$hit = function ($cands, $h) use ($K) {
    $i = 0;
    foreach ($cands as $c) {
        if ($i++ >= $K) {
            break;
        }
        if (substr((string) $c->code, 0, 4) === $h) {
            return true;
        }
    }

    return false;
};

$raw = $nob = $br = 0;
$i = 0;
foreach ($rows as $g) {
    $name = trim((string) $g->name);
    $h = (string) $g->heading;

    $raw += $hit($retriever->candidates($name, $K), $h) ? 1 : 0;
    $nob += $hit($retriever->candidates($expand($name, null), $K), $h) ? 1 : 0;

    $b = $briefs->brief($name);
    $identity = is_array($b) ? ($b['identity'] ?? null) : null;
    $br += $hit($retriever->candidates($expand($name, $identity), $K), $h) ? 1 : 0;

    if (++$i % 25 === 0) {
        fwrite(STDERR, "$i/$n\n");
    }
}

echo "\n=== VECTOR-FLOW recall@{$K}  (n={$n}, brief-only, precedents ON) ===\n";
printf("naive raw name:     %5.1f%%\n", 100 * $raw / $n);
printf("flow, NO brief:     %5.1f%%\n", 100 * $nob / $n);
printf("flow, WITH brief:   %5.1f%%   (Δ vs no-brief %+.1f pp)\n", 100 * $br / $n, 100 * ($br - $nob) / $n);
echo "done\n";
