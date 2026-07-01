<?php

namespace App\Console\Commands;

use App\Models\RubricatorNode;
use App\Services\Llm\OpenRouterClient;
use App\Support\LlmLog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fills titles for rubricator nodes that have none — services (whose catalog
 * names are flat, so nothing is derivable) and any goods gaps. One offline LLM
 * call per node, summarizing the node's sample leaves into a short Azerbaijani
 * category title. Resumable: only untitled nodes are processed.
 */
class GenerateRubricTitles extends Command
{
    protected $signature = 'rubricator:generate-titles
        {--limit=0 : Max nodes to title this run (0 = all remaining)}
        {--model= : Override the LLM model}';

    protected $description = 'AI-generate Azerbaijani titles for untitled rubricator nodes';

    public function handle(OpenRouterClient $llm): int
    {
        $model = (string) ($this->option('model') ?: config('services.openrouter.model'));

        $query = RubricatorNode::whereNull('title')->orderBy('level')->orderBy('code');
        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }
        $nodes = $query->get();

        if ($nodes->isEmpty()) {
            $this->info('No untitled nodes.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($nodes->count());
        $done = 0;

        foreach ($nodes as $node) {
            $leaves = $node->sampleLeaves(15)->pluck('name')
                ->map(fn ($n) => mb_substr((string) $n, 0, 120))->all();

            if ($leaves === []) {
                $bar->advance();

                continue;
            }

            try {
                $messages = [
                    ['role' => 'system', 'content' => $this->prompt()],
                    ['role' => 'user', 'content' => "CODE: {$node->code}\nITEMS:\n- ".implode("\n- ", $leaves)],
                ];
                $response = $llm->jsonWithUsage($messages, ['model' => $model]);
                LlmLog::record('rubric_title', $response['model'], $response['usage'], $response['latency_ms'] ?? 0,
                    'ok', $response['raw'] ?? null, $messages, null, null, ['code' => $node->code]);

                $title = trim((string) ($response['data']['title'] ?? ''));
                if ($title !== '') {
                    $node->update(['title' => $title]);
                    $done++;
                }
            } catch (Throwable $e) {
                $this->newLine();
                $this->warn("  {$node->code}: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Titled {$done} of {$nodes->count()} nodes.");

        return self::SUCCESS;
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
        You name a category in Azerbaijan's XİF MN goods & services classifier.
        You receive a category code and a sample of the member items under it.
        Output a SHORT Azerbaijani category title (2-6 words) capturing what the
        whole group IS. No trailing punctuation, no code, no quotes in the value.
        Respond with strict JSON only: {"title": "..."}
        PROMPT;
    }
}
