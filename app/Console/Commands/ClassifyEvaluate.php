<?php

namespace App\Console\Commands;

use App\Services\Classify\ClassifierService;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ClassifyEvaluate extends Command
{
    protected $signature = 'classify:evaluate
        {file=start-data/task 2/e-invoice_Data_samples_goods.xlsx}
        {--per=3 : Items sampled per GROUP}';

    protected $description = 'Classify a stratified sample of real goods items and report quality metrics';

    /**
     * Rough expected HS chapters per source GROUP (a sanity proxy, since the
     * sample is labelled with internal groups, not XİF MN codes).
     *
     * @var array<string, array<int, string>>
     */
    private array $expected = [
        'BAKERY' => ['19', '17', '18', '21'],
        'CANNED FISH' => ['16', '03'],
        'WIPES' => ['48', '56', '34'],
        'NAPKINS' => ['48', '56'],
        'TOILET PAPER' => ['48'],
        'PAPER TOWEL' => ['48'],
        'MED.SYRINGE' => ['90'],
        'CATHETER' => ['90'],
        'ENEMA' => ['90'],
        'TOWEL' => ['63', '60', '52', '61', '62'],
        'WATER' => ['22', '99', '28'],
    ];

    public function handle(ClassifierService $classifier): int
    {
        ini_set('memory_limit', '1024M');
        $path = $this->argument('file');
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $rows = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);
        array_shift($rows); // header: MƏHSULUN ADI, GROUP, Aİ QRUP

        // Group items by GROUP.
        $byGroup = [];
        foreach ($rows as $r) {
            $name = trim((string) ($r[0] ?? ''));
            $group = trim((string) ($r[1] ?? ''));
            if ($name !== '' && $group !== '') {
                $byGroup[$group][] = $name;
            }
        }

        $per = max(1, (int) $this->option('per'));
        $results = [];
        $tokens = 0;

        foreach ($byGroup as $group => $items) {
            // Evenly spaced sample for variety.
            $n = min($per, count($items));
            $step = max(1, intdiv(count($items), $n));
            for ($i = 0, $picked = 0; $picked < $n && $i < count($items); $i += $step, $picked++) {
                $item = $items[$i];
                $r = $classifier->classify($item);
                $tokens += $r['usage']['total_tokens'] ?? 0;
                $chapter = $r['code'] ? substr($r['code'], 0, 2) : '—';
                $results[] = [
                    'group' => $group,
                    'item' => $item,
                    'kind' => $r['kind'] ?? '—',
                    'code' => $r['code'] ?? '—',
                    'chapter' => $chapter,
                    'conf' => $r['confidence'] !== null ? (int) round($r['confidence'] * 100) : 0,
                    'sim' => $r['semantic_sim'] !== null ? number_format($r['semantic_sim'], 2) : '—',
                    'status' => $r['status'],
                    'plausible' => $this->plausible($group, $chapter),
                ];
            }
        }

        $this->render($results, $tokens);

        return self::SUCCESS;
    }

    private function plausible(string $group, string $chapter): ?bool
    {
        $up = mb_strtoupper($group);
        foreach ($this->expected as $key => $chapters) {
            if (str_contains($up, $key)) {
                return in_array($chapter, $chapters, true);
            }
        }

        return null; // no rule -> not scored
    }

    /** @param array<int, array<string, mixed>> $results */
    private function render(array $results, int $tokens): void
    {
        $this->table(
            ['Group', 'Item', 'Kind', 'Code', 'Ch', 'Conf', 'Sim', 'Status', 'Plaus.'],
            array_map(fn ($r) => [
                mb_substr($r['group'], 0, 16),
                mb_substr($r['item'], 0, 36),
                $r['kind'],
                $r['code'],
                $r['chapter'],
                $r['conf'].'%',
                $r['sim'],
                $r['status'],
                $r['plausible'] === null ? '—' : ($r['plausible'] ? 'yes' : 'NO'),
            ], $results),
        );

        $total = count($results);
        $withCode = count(array_filter($results, fn ($r) => $r['code'] !== '—'));
        $goods = count(array_filter($results, fn ($r) => $r['kind'] === 'good'));
        $auto = count(array_filter($results, fn ($r) => $r['status'] === 'auto_confirmed'));
        $scored = array_filter($results, fn ($r) => $r['plausible'] !== null);
        $plaus = count(array_filter($scored, fn ($r) => $r['plausible'] === true));

        $pct = fn ($n, $d) => $d ? round(100 * $n / $d).'%' : 'n/a';

        $this->newLine();
        $this->info('Summary ('.$total.' items):');
        $this->line('  Matched a code:       '.$withCode.'/'.$total.' ('.$pct($withCode, $total).')');
        $this->line('  Kind = good:          '.$goods.'/'.$total.' ('.$pct($goods, $total).')   (sample is all goods)');
        $this->line('  Auto-confirmed:       '.$auto.'/'.$total.' ('.$pct($auto, $total).')');
        $this->line('  Plausible HS chapter: '.$plaus.'/'.count($scored).' ('.$pct($plaus, count($scored)).')   (proxy accuracy)');
        $this->line('  Tokens used:          '.number_format($tokens, 0, '.', ' '));
    }
}
