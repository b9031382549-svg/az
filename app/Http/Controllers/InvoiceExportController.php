<?php

namespace App\Http\Controllers;

use App\Models\EInvoice;
use App\Models\ImportBatch;
use App\Services\Export\InvoiceLinesExporter;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoice lines + our answer as .xlsx (InvoiceLinesExporter), over a plain GET linked from the
 * Invoices page (its filters: search, upload, status) and from an upload on the Upload page.
 * A selection bigger than one file is downloaded part by part (?part=2 …).
 */
class InvoiceExportController extends Controller
{
    public function __invoke(Request $request, InvoiceLinesExporter $exporter): StreamedResponse
    {
        @set_time_limit(115); // nginx gives the request 120 s; a part is sized to fit well inside

        $upload = (string) $request->query('upload', '');
        $upload = Str::isUuid($upload) ? $upload : '';
        $status = (string) $request->query('status', '');
        $term = (string) $request->query('q', '');

        $lines = EInvoice::query()->filtered($term, $upload, $status);
        $total = (clone $lines)->count();
        $parts = InvoiceLinesExporter::parts($total);
        $part = min(max(1, (int) $request->query('part', 1)), $parts);

        Audit::log('invoice.export', [
            'upload' => $upload ?: null, 'status' => $status ?: null, 'q' => $term ?: null,
            'lines' => $total, 'part' => $part, 'parts' => $parts,
        ]);

        $name = $upload !== '' ? (string) (ImportBatch::where('key', $upload)->value('label') ?? 'upload') : 'invoices';
        $filename = Str::slug(pathinfo($name, PATHINFO_FILENAME), '_', 'az') ?: 'invoices';
        $filename .= '_'.now()->format('Ymd_His').($parts > 1 ? "_part{$part}_of_{$parts}" : '').'.xlsx';

        return response()->streamDownload(
            fn () => $exporter->write($lines, 'php://output', $part),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
