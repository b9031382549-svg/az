<?php

// One-off: extract the ensemble-vs-Fedor disagreements from the accuracy run and
// enrich each competing 4-digit heading with its HS scope + a sample catalog name,
// so an adjudicator can rule who is right. Output: storage/app/accuracy/disputes.json

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CatalogCode;
use App\Models\HsCard;

$lines = file(base_path('storage/app/accuracy/results.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$rows = array_map(fn ($l) => json_decode($l, true), $lines);

$disp = array_values(array_filter($rows, fn ($r) => ! ($r['ensemble_ok'] ?? false)));

$heads = [];
foreach ($disp as $r) {
    if (! $r['gold_service'] && $r['gold_heading']) {
        $heads[$r['gold_heading']] = 1;
    }
    foreach (['vector', 'broker', 'direct', 'search'] as $c) {
        $h = $r[$c]['heading'] ?? null;
        if ($h && $h !== '99' && ! ($r[$c]['service'] ?? false)) {
            $heads[$h] = 1;
        }
    }
}
$heads = array_keys($heads);

$desc = [];
foreach ($heads as $h) {
    $card = HsCard::where('code', $h)->where('is_active', true)->first();
    $scope = $card ? trim((string) $card->scope) : '';
    $sample = CatalogCode::where('position', $h)->where('is_active', true)->orderBy('code')->value('name_ru')
        ?: CatalogCode::where('position', $h)->orderBy('code')->value('name');
    $desc[$h] = trim(($scope ? mb_substr($scope, 0, 220) : '').($sample ? '  · пример: '.mb_substr((string) $sample, 0, 90) : ''));
}

$out = [];
foreach ($disp as $r) {
    $picks = [];
    foreach (['vector', 'broker', 'direct', 'search'] as $c) {
        $p = $r[$c] ?? null;
        $picks[$c] = $p ? ($p['service'] ? 'SERVICE' : ($p['heading'] ?: '—')) : '—';
    }
    $out[] = [
        'name' => $r['name'],
        'fedor' => $r['gold_service'] ? 'SERVICE' : $r['gold_heading'],
        'ensemble' => $r['ensemble']['service'] ? 'SERVICE' : ($r['ensemble']['heading'] ?: '—'),
        'picks' => $picks,
        'consensus' => $r['consensus'] ?? null,
    ];
}

file_put_contents(base_path('storage/app/accuracy/disputes.json'),
    json_encode(['count' => count($out), 'headings' => $desc, 'rows' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo 'disputes: '.count($out)."\n";
echo 'headings described: '.count($desc)."\n";
