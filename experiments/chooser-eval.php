<?php

/**
 * chooser-eval — offline A/B for the "constrained chooser" idea for the conflict tier.
 *
 * Context: when the classifier's mechanisms disagree (conflict), the item goes to the
 * web-search resolver, which re-identifies the item from scratch (~59% on the leak-free
 * held-out run #26). Diagnosis showed the correct heading was ALREADY on the table in ~69%
 * of conflicts — direct's answer or the vector's top-3 — the resolver just threw it away.
 * This script TESTS the alternative: instead of a free web re-ID, give an LLM the shortlist
 * {direct's heading} ∪ {vector top-3} with each heading's official name and ask it to CHOOSE
 * the best one (or NONE). No web. Measures the real recovery rate vs the ~69% ceiling and vs
 * the current search tier — on the same conflict items.
 *
 * First result (2026-08-27, run #26, model gpt-4o-mini, no web): CHOOSER 40.5% vs SEARCH
 * 58.8% on the same 257 conflicts; gold-in-candidates 69.3%; recovery 104/178 = 58.4%. So the
 * pure chooser (this model, no web/brief) did NOT beat the web search. CAVEAT: the comparison
 * is confounded — the search tier uses deepseek-flash WITH web, the chooser used gpt-4o-mini
 * WITHOUT web/brief. The clean signal is the model-independent 69% ceiling (gold present in the
 * shortlist). To re-test fairly: a stronger model + the product brief, and/or widen the
 * candidate pool (top-5 + precedents) to lift the 69% ceiling.
 *
 * Read-only. One LLM call per conflict item. Prefer a FAST OpenRouter model — gpu:base falls
 * back to Token Factory Llama-70B (~50s/call) when no GPU slot is up, which makes this take
 * hours. Run inside the app container:
 *   docker compose exec -T worker php experiments/chooser-eval.php [runId=26] [model=openai/gpt-4o-mini]
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ClassificationItem;
use App\Models\RubricatorNode;
use App\Models\TestDatasetRow;
use App\Services\Classify\HeadingMatch;
use App\Services\Llm\OpenRouterClient;

// Stream progress even through a pipe: drop any output buffering the SAPI/php.ini set up.
while (ob_get_level() > 0) {
    ob_end_flush();
}

$runId = (int) ($argv[1] ?? 26);
$model = (string) ($argv[2] ?? 'openai/gpt-4o-mini');

/** Canonical answer key: '99' for a service, else the 4-digit heading, or null. */
function keyOf(?string $code, ?string $kind): ?string
{
    if ($code === null || $code === '') {
        return null;
    }

    return HeadingMatch::isService($kind, $code) ? '99' : HeadingMatch::heading($code);
}

/** Official heading title for the shortlist (from the rubricator). */
function hname(string $h): string
{
    if ($h === '99') {
        return 'Services / labour (chapter 99)';
    }

    return RubricatorNode::where('code', $h)->value('title') ?: "(heading {$h})";
}

$llm = app(OpenRouterClient::class);
$items = ClassificationItem::where('test_run_id', $runId)
    ->whereHas('results', fn ($q) => $q->where('mechanism', 'search'))
    ->with('results')->get();

echo "start: {$items->count()} conflict items | run #{$runId} | model={$model}\n";
flush();

$tot = 0;
$chooserOK = 0;
$searchOK = 0;
$goldInCand = 0;
$present = 0;
$absent = 0;
$pickGoldWhenPresent = 0;
$saidNoneWhenAbsent = 0;
$pickedWrongWhenAbsent = 0;
$err = 0;

foreach ($items as $it) {
    $row = TestDatasetRow::find($it->test_dataset_row_id);
    if (! $row) {
        continue;
    }
    $bm = $it->results->keyBy('mechanism');
    $di = $bm->get('direct');
    $ve = $bm->get('vector');
    $se = $bm->get('search');
    $goldSvc = (bool) $row->expected_is_service;
    $goldKey = $goldSvc ? '99' : $row->expected_heading;

    // Shortlist = direct's heading + the vector's top-3 headings (deduped).
    $cands = [];
    if ($dk = keyOf($di?->matched_code, $di?->kind)) {
        $cands[] = $dk;
    }
    if ($ve) {
        foreach ($ve->topHeadings(3) as $vh) {
            if ($k = keyOf($vh, 'good')) {
                $cands[] = $k;
            }
        }
    }
    $cands = array_values(array_unique($cands));
    if ($cands === []) {
        continue;
    }
    $tot++;
    $inCand = in_array($goldKey, $cands, true);
    $inCand ? $present++ : $absent++;
    if ($inCand) {
        $goldInCand++;
    }

    $list = '';
    foreach ($cands as $c) {
        $list .= '  '.$c.' - '.hname($c)."\n";
    }
    $sys = 'You classify one Azerbaijani e-invoice line item to a 4-digit XIF MN / HS heading. '
        .'Two automatic methods disagreed, so CHOOSE the correct heading from the SHORTLIST (these '
        .'are the ONLY allowed answers). Pick the ONE whose description best matches the item. If none '
        .'of them fits, answer NONE. Respond strict JSON only: {"heading":"<one listed code, or NONE>","reason":"short"}';
    $usr = 'ITEM: '.$row->source_text."\n\nSHORTLIST (choose one):\n".$list;

    try {
        $resp = $llm->complete(
            [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $usr]],
            ['model' => $model, 'timeout' => 40],
        );
        $j = json_decode(preg_replace('/^```json|```$/m', '', trim($resp['content'])), true);
        $pick = $j['heading'] ?? 'NONE';
    } catch (\Throwable $e) {
        $pick = 'ERR';
        $err++;
    }

    if ($pick !== 'NONE' && $pick !== 'ERR' && $pick === $goldKey) {
        $chooserOK++;
    }
    if (HeadingMatch::correct($se?->matched_code, $se?->kind, $row->expected_heading, $goldSvc)) {
        $searchOK++;
    }
    if ($inCand) {
        if ($pick === $goldKey) {
            $pickGoldWhenPresent++;
        }
    } elseif ($pick === 'NONE') {
        $saidNoneWhenAbsent++;
    } elseif ($pick !== 'ERR') {
        $pickedWrongWhenAbsent++;
    }

    if ($tot % 20 === 0) {
        echo "progress: {$tot}/{$items->count()} | chooser_ok={$chooserOK} search_ok={$searchOK} err={$err}\n";
        flush();
    }
}

$pct = fn ($n, $d) => $d > 0 ? sprintf('%.1f%%', 100 * $n / $d) : '—';
echo "\n=== CHOOSER EVAL — run #{$runId}, {$tot} conflict items, err={$err} ===\n";
echo "CHOOSER correct:  {$chooserOK} (".$pct($chooserOK, $tot).")   vs   current SEARCH: {$searchOK} (".$pct($searchOK, $tot).") on the same items\n";
echo "gold in candidates: {$goldInCand} (".$pct($goldInCand, $tot).")   <- the ceiling of any chooser over {direct + vector top-3}\n";
echo "recovery (gold present -> chooser picked it): {$pickGoldWhenPresent}/{$present} (".$pct($pickGoldWhenPresent, $present).")\n";
echo "gold NOT in shortlist ({$absent}): said NONE {$saidNoneWhenAbsent} (".$pct($saidNoneWhenAbsent, $absent)."), picked WRONG {$pickedWrongWhenAbsent} (".$pct($pickedWrongWhenAbsent, $absent).")\n";
echo "DONE\n";
