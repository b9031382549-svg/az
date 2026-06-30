<?php

namespace App\Console\Commands;

use App\Services\Classify\ClassifierService;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Dev-only: calibrate the auto-confirm semantic threshold (classify.min_semantic).
 * Runs the labelled goods sample, and for confidently-picked items (confidence >=
 * auto_confirm) sweeps the min_semantic gate to show, at each threshold, how many
 * items would auto-confirm and how many of those are actually correct (plausible
 * HS chapter). Lets us pick a threshold that auto-confirms the correct picks
 * without letting wrong ones through. Throwaway.
 */
class ClassifyCalibrate extends Command
{
    protected $signature = 'classify:calibrate
        {--per=6 : Items sampled per GROUP}
        {--file=start-data/task 2/e-invoice_Data_samples_goods.xlsx}';

    protected $description = 'Calibrate the min_semantic auto-confirm threshold against the labelled sample';

    /** @var array<string, array<int, string>> GROUP -> plausible HS chapters */
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
        $autoConfirm = (float) config('classify.auto_confirm');
        $current = (float) config('classify.min_semantic');

        $sample = $this->sample((int) $this->option('per'));
        $this->info(count($sample).' items; auto_confirm(conf)='.$autoConfirm.'  current min_semantic='.$current);
        $this->newLine();

        // Collect (sim, plausible) for confidently-picked, scoreable items.
        $rows = [];
        $bar = $this->output->createProgressBar(count($sample));
        foreach ($sample as [$group, $item]) {
            $r = $classifier->classify($item);
            $bar->advance();
            if (! $r['code'] || $r['semantic_sim'] === null || ($r['confidence'] ?? 0) < $autoConfirm) {
                continue; // only items the SEMANTIC gate decides
            }
            $plaus = $this->plausible($group, substr((string) $r['code'], 0, 2));
            if ($plaus === null) {
                continue; // no ground-truth rule -> can't score
            }
            $rows[] = ['sim' => (float) $r['semantic_sim'], 'ok' => $plaus];
        }
        $bar->finish();
        $this->newLine(2);

        $scored = count($rows);
        if ($scored === 0) {
            $this->warn('No confidently-picked scoreable items in the sample. Increase --per.');

            return self::SUCCESS;
        }

        $totalCorrect = count(array_filter($rows, fn ($x) => $x['ok']));
        $this->line("Confidently-picked & scoreable items: {$scored}  (of which correct: {$totalCorrect})");
        $this->newLine();

        // Sweep the semantic threshold.
        $table = [];
        foreach ([0.40, 0.45, 0.50, 0.55, 0.60, 0.65, 0.70] as $tau) {
            $auto = array_filter($rows, fn ($x) => $x['sim'] >= $tau);
            $autoN = count($auto);
            $correct = count(array_filter($auto, fn ($x) => $x['ok']));
            $wrong = $autoN - $correct;
            $precision = $autoN ? round(100 * $correct / $autoN).'%' : '—';
            $coverage = $totalCorrect ? round(100 * $correct / $totalCorrect).'%' : '—';
            $table[] = [
                ($tau === $current ? '* ' : '  ').number_format($tau, 2),
                $autoN.'/'.$scored,
                $correct,
                $wrong,
                $precision,
                $coverage,
            ];
        }

        $this->table(
            ['min_semantic', 'auto-confirmed', 'correct', 'WRONG', 'precision', 'coverage of correct'],
            $table,
        );
        $this->line('  * = current threshold · precision = correct / auto-confirmed · coverage = correct auto-confirmed / all correct');
        $this->line('  Goal: lowest threshold that keeps WRONG≈0 (precision ~100%) while raising coverage.');

        return self::SUCCESS;
    }

    /** @return array<int, array{0:string,1:string}> */
    private function sample(int $per): array
    {
        $path = (string) $this->option('file');
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $rows = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);
        array_shift($rows);

        $byGroup = [];
        foreach ($rows as $r) {
            $name = trim((string) ($r[0] ?? ''));
            $group = trim((string) ($r[1] ?? ''));
            if ($name !== '' && $group !== '') {
                $byGroup[$group][] = $name;
            }
        }

        $sample = [];
        foreach ($byGroup as $group => $items) {
            $n = min(max(1, $per), count($items));
            $step = max(1, intdiv(count($items), $n));
            for ($i = 0, $picked = 0; $picked < $n && $i < count($items); $i += $step, $picked++) {
                $sample[] = [$group, $items[$i]];
            }
        }

        return $sample;
    }

    private function plausible(string $group, string $chapter): ?bool
    {
        $up = mb_strtoupper($group);
        foreach ($this->expected as $key => $chapters) {
            if (str_contains($up, $key)) {
                return in_array($chapter, $chapters, true);
            }
        }

        return null;
    }
}
