<?php

namespace App\Http\Controllers;

use App\Models\EInvoice;
use Carbon\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $agg = EInvoice::query()
            ->selectRaw('count(*) as invoices')
            ->selectRaw('coalesce(sum(total_amount),0) as turnover')
            ->selectRaw('coalesce(sum(vat_amount),0) as vat')
            ->selectRaw('count(distinct supplier_tin) as suppliers')
            ->first();

        $monthsRaw = EInvoice::query()
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

        return view('pages.overview', compact('agg', 'months', 'recent'));
    }
}
