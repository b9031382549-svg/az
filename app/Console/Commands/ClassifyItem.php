<?php

namespace App\Console\Commands;

use App\Services\Classify\ClassifierService;
use Illuminate\Console\Command;

class ClassifyItem extends Command
{
    protected $signature = 'classify:item {text* : The item description to classify} {--save}';

    protected $description = 'Classify a line item as good/service and assign an XİF MN code';

    public function handle(ClassifierService $classifier): int
    {
        $text = implode(' ', $this->argument('text'));
        $this->info('Item: '.$text);

        $r = $classifier->classify($text);

        if ($r['error']) {
            $this->error('Error: '.$r['error']);
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Kind:       <info>'.($r['kind'] ?? '—').'</info>');
        $this->line('  Code:       <info>'.($r['code'] ?? '—').'</info>');
        $this->line('  Name:       '.($r['name'] ?? '—'));
        $this->line('  Confidence: '.($r['confidence'] ?? '—'));
        $this->line('  Status:     <comment>'.$r['status'].'</comment>');
        $this->line('  Reason:     '.($r['reason'] ?? '—'));
        if ($r['usage']) {
            $this->line('  Tokens:     '.$r['usage']['total_tokens'].' (prompt '.$r['usage']['prompt_tokens'].' / completion '.$r['usage']['completion_tokens'].')');
        }

        $this->newLine();
        $this->line('  <comment>Top candidates:</comment>');
        foreach (array_slice($r['candidates'], 0, 5) as $c) {
            $this->line('   '.$c['code'].' ['.$c['kind'].']  '.mb_substr($c['name'], 0, 70));
        }

        if ($this->option('save')) {
            $classifier->record($r);
            $this->info('Saved to classifications.');
        }

        return self::SUCCESS;
    }
}
