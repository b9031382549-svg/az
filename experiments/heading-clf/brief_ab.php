<?php

// Resumable A/B of brief-identity variants + "precedents on top of brief".
// identity-only retrieval, recall@24, N Fedor gold goods. Per-item result appended to
// OUT (JSONL) and skipped on restart, so mid-run deaths just resume. Read-only.

use App\Services\Classify\CatalogRetriever;
use App\Services\Classify\ProductBriefService;
use App\Services\Embeddings\OllamaEmbedder;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$N = (int) (getenv('N') ?: 150);
$K = 24;
$OUT = getenv('OUT') ?: '/var/www/html/experiments/heading-clf/results.jsonl';
$doVar = getenv('VARIANTS') !== '0';

$rows = DB::table('gold_labels')->where('source', 'fedor')->whereNotNull('heading')
    ->orderBy('id')->limit($N)->get(['name', 'heading'])->all();
$n = count($rows);

$done = [];
if (is_file($OUT)) {
    foreach (file($OUT) as $l) {
        $o = json_decode($l, true);
        if (is_array($o) && isset($o['name'])) {
            $done[$o['name']] = true;
        }
    }
}
fwrite(STDERR, "items: {$n}, already done: ".count($done)."\n");

$briefs = app(ProductBriefService::class);
$llm = app(OpenRouterClient::class);
$emb = OllamaEmbedder::fromConfig();
$model = (string) config('classify.broker.brief_model', 'openai/gpt-4o');

config(['classify.precedents.enabled' => true, 'classify.precedents.top_k' => 40, 'classify.precedents.per_heading' => 2]);
$retOn = new CatalogRetriever($emb);
config(['classify.precedents.enabled' => false]);
$retOff = new CatalogRetriever($emb);

$V = [
    'A_az' => 'You normalize noisy Azerbaijani e-invoice line items for a customs classifier. Output ONLY the canonical product name in AZERBAIJANI — the core commodity as it appears in a customs nomenclature (1-4 words, nominative). Drop brand, model/article codes, size, packaging, quantity, colour. Output only the name, nothing else.',
    'B_en' => 'You normalize noisy Azerbaijani e-invoice line items for a customs classifier. Output ONLY the canonical product name in ENGLISH — the core commodity (1-4 words). Drop brand, model/article codes, size, packaging, quantity, colour. Output only the name, nothing else.',
    'C_az_fs' => "You normalize noisy Azerbaijani e-invoice line items for a customs classifier. Output ONLY the canonical product name in AZERBAIJANI (1-4 words, nominative), the core commodity, dropping brand, codes, size, packaging, quantity. Output only the name.\nExamples:\nBeluga Nobl 0.05 L => araq\nPRINGLES ALL ONIONS 70GR => kartof çipsi\nTemol 10w40 Luxe Diesel => mühərrik yağı\nPetlə (sağ) => qapı rəzəsi\nNUTRIGOLD 25 KQ => gübrə\nkovun => yemiş\n",
];

$idOf = function ($sys, $text) use ($llm, $model) {
    for ($try = 0; $try < 2; $try++) {
        try {
            $r = $llm->complete(
                [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => 'ITEM: '.$text]],
                ['model' => $model, 'timeout' => 60],
            );
            $c = trim((string) ($r['content'] ?? ''));

            return trim(trim(explode("\n", $c)[0]), "\"' .-");
        } catch (\Throwable $e) {
        }
    }

    return '';
};

$hit = function (CatalogRetriever $ret, string $q, string $h) use ($K) {
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
        $id0 = is_array($b) ? trim((string) ($b['identity'] ?? '')) : '';
    } catch (\Throwable $e) {
        $id0 = '';
    }
    $rec = ['name' => $name, 'heading' => $h, 'id0' => $id0,
        'base_on' => $hit($retOn, $id0, $h), 'base_off' => $hit($retOff, $id0, $h)];
    if ($doVar) {
        foreach ($V as $k => $sys) {
            $idv = $idOf($sys, $name);
            $rec['id_'.$k] = $idv;
            $rec[$k] = $hit($retOn, $idv, $h);
        }
    }
    fwrite($fh, json_encode($rec, JSON_UNESCAPED_UNICODE)."\n");
    fflush($fh);
    if ($i % 10 === 0) {
        fwrite(STDERR, "$i/$n\n");
    }
}
fclose($fh);
fwrite(STDERR, "loop done\n");

// aggregate from file
$cols = ['base_on', 'base_off', 'A_az', 'B_en', 'C_az_fs'];
$sum = array_fill_keys($cols, 0);
$cnt = 0;
foreach (file($OUT) as $l) {
    $o = json_decode($l, true);
    if (! is_array($o)) {
        continue;
    }
    $cnt++;
    foreach ($cols as $c) {
        $sum[$c] += (int) ($o[$c] ?? 0);
    }
}
echo "\n=== identity-only recall@{$K}  (aggregated n={$cnt}) ===\n";
echo "--- Part A: precedents ON TOP of brief ---\n";
printf("baseline id, precedents ON:  %5.1f%%\n", 100 * $sum['base_on'] / max(1, $cnt));
printf("baseline id, precedents OFF: %5.1f%%   (precedents Δ %+.1f pp)\n",
    100 * $sum['base_off'] / max(1, $cnt), 100 * ($sum['base_on'] - $sum['base_off']) / max(1, $cnt));
echo "--- Part B: brief-identity variants (precedents ON) ---\n";
printf("baseline (current brief): %5.1f%%\n", 100 * $sum['base_on'] / max(1, $cnt));
foreach (['A_az', 'B_en', 'C_az_fs'] as $k) {
    printf("%-9s:               %5.1f%%   (Δ vs baseline %+.1f pp)\n",
        $k, 100 * $sum[$k] / max(1, $cnt), 100 * ($sum[$k] - $sum['base_on']) / max(1, $cnt));
}
echo "done\n";
