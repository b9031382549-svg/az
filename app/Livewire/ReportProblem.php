<?php

namespace App\Livewire;

use App\Models\BugReport;
use App\Support\Audit;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * The "Report a problem" footer popup (rendered in the app layout on every
 * page). Captures the page's request_id at mount and saves the user's message
 * to bug_reports, so a report can be traced against the audit / LLM logs.
 */
class ReportProblem extends Component
{
    public string $message = '';

    public string $requestId = '';

    public string $url = '';

    public bool $sent = false;

    public function mount(): void
    {
        // Captured during the page render — the id shown in the popup and saved
        // with the report (not the later /livewire/update request's id).
        $id = app()->bound('request_id') ? (string) app('request_id') : '';
        $this->requestId = Str::isUuid($id) ? $id : ''; // column is native uuid
        $this->url = Str::limit((string) request()->fullUrl(), 1000, '');
    }

    /** Reset the form when the popup is (re)opened. */
    public function resetForm(): void
    {
        $this->reset('message', 'sent');
        $this->resetValidation();
    }

    public function submit(): void
    {
        $data = $this->validate([
            'message' => ['required', 'string', 'min:3', 'max:5000'],
        ]);

        $report = BugReport::create([
            'user_id' => auth()->id(),
            'request_id' => $this->requestId ?: null,
            'message' => $data['message'],
            'url' => $this->url ?: null,
            'status' => 'open',
        ]);

        Audit::log('bug.report', ['request_id' => $this->requestId, 'url' => $this->url], $report);

        $this->reset('message');
        $this->sent = true;
    }

    public function render()
    {
        return view('livewire.report-problem');
    }
}
