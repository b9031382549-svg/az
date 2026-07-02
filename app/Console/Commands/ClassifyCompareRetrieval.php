<?php

namespace App\Console\Commands;

use App\Services\Classify\ClassifierService;
use Illuminate\Console\Command;

/**
 * Dev-only A/B: prove the universal retrieval (multi-query + noise stripping,
 * no trap dictionary) matches or beats the legacy single-query + traps approach
 * on both the new failures (canned tuna, fish burgers) and the old "trap" cases
 * (tea towel, grillage sweet, paper tissue), plus control items that must NOT be
 * over-corrected. Reports retrieval recall and final-pick plausibility.
 * Throwaway, safe to delete.
 */
class ClassifyCompareRetrieval extends Command
{
    protected $signature = 'classify:compare-retrieval';

    protected $description = 'Compare legacy (traps) vs universal retrieval on recall + final pick';

    /** @var array<int, array{name:string, exp:array<int,string>, note:string}> */
    private array $items = [
        ['name' => 'SUPERFRESH TON KLASIK AYCICEK(140GRX2LI)280GR 1X12', 'exp' => ['16', '03'], 'note' => 'canned tuna (new fail)'],
        ['name' => '4770190379656-zam. VICI Lyubo yest ribniye burgeri 500 qr', 'exp' => ['16', '03'], 'note' => 'fish burgers (new fail)'],
        ['name' => 'SARDINA tomatda 240qr', 'exp' => ['16', '03'], 'note' => 'canned sardines'],
        ['name' => 'OWOM ÇAY DƏSMALI ZEYTUN', 'exp' => ['63', '61', '62', '60', '52', '48'], 'note' => 'tea TOWEL (trap)'],
        ['name' => 'qrilyaj konfet 200 qr', 'exp' => ['17', '18', '20', '08'], 'note' => 'grillage sweet (trap)'],
        ['name' => 'ZEWA cib salfeti 10 ed', 'exp' => ['48'], 'note' => 'paper pocket tissue (trap)'],
        ['name' => '5337 ZEWA DELUXE BRT 8 3PLY CAMOMILE', 'exp' => ['48'], 'note' => 'toilet/tissue paper'],
        ['name' => 'Şpris 5ml 23G rezin porşenli', 'exp' => ['90'], 'note' => 'syringe (regression guard)'],
        ['name' => 'Qara çay Azərçay 100 qr', 'exp' => ['09'], 'note' => 'REAL tea -> 09 not towel (control)'],
        ['name' => 'Günəbaxan yağı 1L', 'exp' => ['15'], 'note' => 'sunflower OIL -> 15 not fish (control)'],
    ];

    public function handle(ClassifierService $classifier): int
    {
        $modes = [
            'OLD' => ['classify.multi_query' => false, 'classify.use_traps' => true],
            'NEW' => ['classify.multi_query' => true, 'classify.use_traps' => false],
        ];

        $res = []; // res[itemIdx][mode] = [chapter, pick, recall]
        foreach ($modes as $mode => $cfg) {
            config($cfg);
            $this->line("==> {$mode} ".json_encode($cfg));
            foreach ($this->items as $i => $item) {
                $r = $classifier->classify($item['name']);
                $chapter = $r['code'] ? substr((string) $r['code'], 0, 2) : '—';
                $candChapters = array_map(fn ($c) => substr((string) $c['code'], 0, 2), $r['candidates']);
                $res[$i][$mode] = [
                    'chapter' => $chapter,
                    'pick' => in_array($chapter, $item['exp'], true),
                    'recall' => count(array_intersect($candChapters, $item['exp'])) > 0,
                ];
                $this->getOutput()->write('.');
            }
            $this->newLine();
        }

        $this->renderTable($res);
        $this->renderSummary($res, array_keys($modes));

        return self::SUCCESS;
    }

    /** @param array<int, array<string, array{chapter:string,pick:bool,recall:bool}>> $res */
    private function renderTable(array $res): void
    {
        $rows = [];
        foreach ($this->items as $i => $item) {
            $cell = function (array $c) {
                $r = $c['recall'] ? 'R' : '·';
                $p = $c['pick'] ? '✓' : '✗';

                return "{$c['chapter']} {$r}{$p}";
            };
            $rows[] = [
                mb_substr($item['note'], 0, 26),
                implode('/', $item['exp']),
                $cell($res[$i]['OLD']),
                $cell($res[$i]['NEW']),
            ];
        }

        $this->newLine();
        $this->table(['Item', 'Expected', 'OLD (traps)', 'NEW (universal)'], $rows);
        $this->line('  R = correct chapter was in candidates (recall) · ✓/✗ = final pick plausible');
    }

    /**
     * @param  array<int, array<string, array{chapter:string,pick:bool,recall:bool}>>  $res
     * @param  array<int, string>  $modes
     */
    private function renderSummary(array $res, array $modes): void
    {
        $n = count($this->items);
        $rows = [];
        foreach ($modes as $m) {
            $recall = $pick = 0;
            foreach ($res as $r) {
                $recall += $r[$m]['recall'] ? 1 : 0;
                $pick += $r[$m]['pick'] ? 1 : 0;
            }
            $rows[] = [$m, "{$recall}/{$n} (".round(100 * $recall / $n).'%)', "{$pick}/{$n} (".round(100 * $pick / $n).'%)'];
        }
        $this->newLine();
        $this->info('Summary:');
        $this->table(['Mode', 'Recall (correct ch in candidates)', 'Final pick plausible'], $rows);
    }
}
