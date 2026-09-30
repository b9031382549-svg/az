<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Services\Classify\DecisionSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only JSON API over classification results — one item (with every
 * mechanism's full decision trace) or all items of an upload (batch uuid).
 * Each item also says how its answer was found and why (method + reason, the
 * same lines as the Excel export). Guarded by ApiKeyAuth.
 */
class ResultsApiController extends Controller
{
    public function __construct(private readonly DecisionSummary $summary) {}

    /** GET /api/results/{item} — one item with full per-mechanism traces. */
    public function result(int $item): JsonResponse
    {
        $it = ClassificationItem::with(['results', 'finalCode'])
            ->whereNull('test_run_id') // the API exposes production results, not dataset test rows
            ->find($item);
        if ($it === null) {
            return response()->json(['error' => 'Item not found.'], 404);
        }

        return response()->json($this->payload($it, $this->summary->forItems(collect([$it]))[$it->id], full: true));
    }

    /** GET /api/uploads/{batch} — compact list of an upload's items. */
    public function upload(Request $request, string $batch): JsonResponse
    {
        $limit = min(1000, max(1, (int) $request->query('limit', 200)));
        $base = ClassificationItem::where('batch', $batch)->whereNull('test_run_id');

        $total = (int) (clone $base)->count();
        if ($total === 0) {
            return response()->json(['error' => 'No items for this upload.', 'batch' => $batch], 404);
        }

        $resolutions = (clone $base)->selectRaw('resolution, count(*) as c')
            ->groupBy('resolution')->pluck('c', 'resolution');

        $items = (clone $base)
            ->with('results')
            ->when($request->query('resolution'), fn ($q, $r) => $q->where('resolution', $r))
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $summaries = $this->summary->forItems($items);
        $items = $items->map(fn ($it) => $this->payload($it, $summaries[$it->id], full: false));

        return response()->json([
            'batch' => $batch,
            'total' => $total,
            'returned' => $items->count(),
            'limit' => $limit,
            'resolutions' => $resolutions,
            'items' => $items,
        ]);
    }

    /**
     * @param  array{method: string, reason: string}  $summary  how the answer was found and why (DecisionSummary)
     * @return array<string, mixed>
     */
    private function payload(ClassificationItem $it, array $summary, bool $full): array
    {
        $data = [
            'id' => $it->id,
            'batch' => $it->batch,
            'source_text' => $it->source_text,
            'kind' => $it->kind,
            'resolution' => $it->resolution,
            'final_code' => $it->final_code,
            'method' => $summary['method'],
            'reason' => $summary['reason'],
        ];

        // Stable order for consumers: vector first, then broker, then the rest.
        $results = $it->results->sortBy(fn ($r) => ['vector' => 0, 'broker' => 1][$r->mechanism] ?? 9)->values();

        if (! $full) {
            $data['mechanisms'] = $results->mapWithKeys(fn ($r) => [
                $r->mechanism => ['code' => $r->matched_code, 'status' => $r->status, 'confidence' => $r->confidence],
            ]);

            return $data;
        }

        $data['final_name'] = $it->finalCode?->name;
        $data['confirmed_by'] = $it->confirmed_by;
        $data['confirmed_at'] = optional($it->confirmed_at)->toIso8601String();
        $data['results'] = $results->map(fn ($r) => [
            'mechanism' => $r->mechanism,
            // For the vector, matched_code is its top-1 representative; its real answer is
            // the top_headings shortlist that consensus tests membership in.
            'matched_code' => $r->matched_code,
            'top_headings' => $r->mechanism === 'vector' ? $r->topHeadings() : [],
            'name' => optional(CatalogCode::where('code', $r->matched_code)->first('name'))->name,
            'kind' => $r->kind,
            'confidence' => $r->confidence,
            'status' => $r->status,
            'model' => $r->model,
            'tier' => $r->tier,
            'explanation' => $r->explanation,
            'candidates' => $r->candidates,
            'path' => $r->path,
            'trace' => $r->trace,
            'usage' => $r->usage,
        ]);

        return $data;
    }
}
