<?php

namespace App\Http\Controllers;

use App\Models\EInvoice;
use Carbon\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        // A row is an invoice LINE: invoices are the distinct series|number keys; lines of the
        // line-level export that carry no series/number cannot be tied to an invoice.
        $agg = EInvoice::query()
            ->selectRaw('count(distinct invoice_key) as invoices')
            ->selectRaw('sum(case when invoice_key is null then 1 else 0 end) as unidentified')
            ->selectRaw('coalesce(sum(total_amount),0) as turnover')
            ->selectRaw('coalesce(sum(vat_amount),0) as vat')
            ->selectRaw('count(distinct supplier_tin) as suppliers')
            ->selectRaw('min(invoice_date) as first_date')
            ->selectRaw('max(invoice_date) as last_date')
            ->first();

        $period = $this->periodLabel($agg->first_date, $agg->last_date);

        $monthsRaw = EInvoice::query()
            ->whereNotNull('invoice_date') // a line-level export row may carry no date
            ->selectRaw("to_char(invoice_date,'YYYY-MM') as ym, sum(total_amount) as t")
            ->groupBy('ym')->orderBy('ym')->get();

        $max = (float) ($monthsRaw->max('t') ?: 1);
        $months = $monthsRaw->map(fn ($r) => [
            'label' => Carbon::createFromFormat('Y-m', $r->ym)->format('M'),
            'total' => (float) $r->t,
            'pct' => $max > 0 ? max(4, round((float) $r->t / $max * 100)) : 0,
        ]);

        $recent = EInvoice::query()
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->limit(6)->get();

        return view('pages.overview', compact('agg', 'months', 'recent', 'period'));
    }

    private function periodLabel(?string $first, ?string $last): string
    {
        $years = array_filter([
            $first ? Carbon::parse($first)->year : null,
            $last ? Carbon::parse($last)->year : null,
        ]);

        if (empty($years)) {
            return '—';
        }

        return min($years) === max($years)
            ? (string) min($years)
            : min($years).'–'.max($years);
    }
}
