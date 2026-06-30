<?php

namespace App\Http\Controllers;

use App\Models\Classification;
use App\Models\ImportBatch;
use App\Services\Export\ClassificationExporter;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the review-queue results to .xlsx over a plain GET (linked with an
 * <a href> from the queue), scoped to the upload + status filter passed as query
 * params. A GET avoids Livewire buffering the whole file into memory + base64.
 */
class ReviewExportController extends Controller
{
    private const STATUSES = ['needs_review', 'auto_confirmed', 'confirmed', 'rejected', 'no_match', 'error', 'all'];

    private const MAX_ROWS = 20000;

    public function __invoke(Request $request, ClassificationExporter $exporter): StreamedResponse
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(300);

        $filter = (string) $request->query('filter', 'all');
        if (! in_array($filter, self::STATUSES, true)) {
            $filter = 'all';
        }

        $batch = (string) $request->query('batch', 'all');
        $batchOk = $batch !== 'all' && Str::isUuid($batch); // request_id/batch are uuids

        $rows = Classification::query()
            ->with('code:id,name')
            ->when($batchOk, fn ($q) => $q->where('batch', $batch))
            ->when($filter !== 'all', fn ($q) => $q->where('status', $filter))
            ->latest()
            ->limit(self::MAX_ROWS)
            ->get();

        $labels = ImportBatch::whereIn('key', $rows->pluck('batch')->filter()->unique())->pluck('label', 'key');

        Audit::log('classification.export', [
            'batch' => $batchOk ? $batch : 'all',
            'filter' => $filter,
            'rows' => $rows->count(),
        ]);

        $spreadsheet = $exporter->build($rows, $labels);
        $filename = 'classifications_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
