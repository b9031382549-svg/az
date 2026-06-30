<?php

namespace App\Livewire;

use App\Models\ActivityLog;
use App\Models\BugReport;
use App\Models\LlmUsage;
use Livewire\Attributes\Layout;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Audit / activity viewer (route /log, not in the nav). Two tabs: the action
 * audit trail and the LLM decision log, filterable by request_id, action and
 * free text — so a bug report quoting a request_id can be traced end-to-end.
 */
#[Layout('components.app-layout', ['title' => 'Activity log'])]
class Logs extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'activity'; // activity | llm | reports

    #[Url]
    public string $requestId = '';

    #[Url]
    public string $action = ''; // action (activity) / purpose (llm)

    #[Url]
    public string $q = '';

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['llm', 'reports'], true) ? $tab : 'activity';
        $this->action = '';
        $this->resetPage();
    }

    public function updatedRequestId(): void
    {
        $this->resetPage();
    }

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $q = trim($this->q);
        $like = '%'.$q.'%';
        // request_id columns are native Postgres uuid — only filter on a complete,
        // valid uuid, otherwise a partial/typed value would raise SQLSTATE 22P02.
        $reqId = Str::isUuid(trim($this->requestId)) ? trim($this->requestId) : null;

        if ($this->tab === 'llm') {
            $rows = LlmUsage::query()
                ->when($reqId, fn ($x) => $x->where('request_id', $reqId))
                ->when($this->action !== '', fn ($x) => $x->where('purpose', $this->action))
                ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w
                    ->where('model', 'ilike', $like)
                    ->orWhere('prompt', 'ilike', $like)
                    ->orWhere('response', 'ilike', $like)
                    ->orWhere('error', 'ilike', $like)))
                ->latest('id')
                ->paginate(25);

            $actions = LlmUsage::query()->distinct()->orderBy('purpose')->pluck('purpose');
        } elseif ($this->tab === 'reports') {
            $rows = BugReport::query()
                ->with('user')
                ->when($reqId, fn ($x) => $x->where('request_id', $reqId))
                ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w
                    ->where('message', 'ilike', $like)
                    ->orWhere('url', 'ilike', $like)))
                ->latest('id')
                ->paginate(25);

            $actions = collect();
        } else {
            $rows = ActivityLog::query()
                ->with('user')
                ->when($reqId, fn ($x) => $x->where('request_id', $reqId))
                ->when($this->action !== '', fn ($x) => $x->where('action', $this->action))
                ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w
                    ->where('action', 'ilike', $like)
                    ->orWhere('ip', 'ilike', $like)
                    ->orWhereRaw('properties::text ilike ?', [$like])))
                ->latest('id')
                ->paginate(25);

            $actions = ActivityLog::query()->distinct()->orderBy('action')->pluck('action');
        }

        return view('livewire.logs', compact('rows', 'actions'));
    }
}
