<?php

// Experiment B — embed the Fedor gold goods (query side) for the heading classifier.
// Read-only: pulls names from gold_labels, embeds via our local Ollama (bge-m3) in
// BATCHES (fast), prints "heading\t[vector]" to STDOUT. No DB writes.

use App\Services\Embeddings\OllamaEmbedder;
use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$emb = OllamaEmbedder::fromConfig();
$norm = function (string $t): string {
    $w = array_filter(preg_split('/\s+/u', trim($t)) ?: [], fn ($x) => $x !== '' && ! preg_match('/\d/u', $x));
    $c = trim(implode(' ', $w));

    return $c !== '' ? $c : trim($t);
};

$rows = DB::table('gold_labels')->where('source', 'fedor')->whereNotNull('heading')->orderBy('id')->get(['name', 'heading'])->all();
$n = count($rows);
$B = 32;
$done = 0;
for ($s = 0; $s < $n; $s += $B) {
    $chunk = array_slice($rows, $s, $B);
    $texts = array_map(fn ($g) => $norm((string) $g->name), $chunk);
    try {
        $vecs = $emb->embed($texts);
    } catch (\Throwable $e) {
        // fall back to one-by-one for this chunk so one bad item can't drop 32
        $vecs = [];
        foreach ($texts as $t) {
            try {
                $vecs[] = $emb->embedOne($t);
            } catch (\Throwable $e2) {
                $vecs[] = array_fill(0, 1024, 0.0);
            }
        }
    }
    foreach ($chunk as $k => $g) {
        echo $g->heading."\t[".implode(',', $vecs[$k])."]\n";
    }
    $done += count($chunk);
    fwrite(STDERR, "$done/$n\n");
}
fwrite(STDERR, "done $done\n");
