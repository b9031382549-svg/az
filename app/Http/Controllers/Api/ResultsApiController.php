<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only JSON API over classification results — one item (with every
 * mechanism's full decision trace) or all items of an upload (batch uuid).
 * Guarded by ApiKeyAuth.
 */
class ResultsApiController extends Controller
{
    /** GET /api/results/{item} — one item with full per-mechanism traces. */
    public function result(int $item): JsonResponse
    {
        $it = ClassificationItem::with(['results', 'finalCode'])->find($item);
        if ($it === null) {
            return response()->json(['error' => 'Item not found.'], 404);
        }

        return response()->json($this->payload($it, full: true));
    }

    /** GET /api/uploads/{batch} — compact list of an upload's items. */
    public function upload(Request $request, string $batch): JsonResponse
    {
        $limit = min(1000, max(1, (int) $request->query('limit', 200)));
        $base = ClassificationItem::where('batch', $batch);

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
            ->get()
            ->map(fn ($it) => $this->payload($it, full: false));

        return response()->json([
            'batch' => $batch,
            'total' => $total,
            'returned' => $items->count(),
            'limit' => $limit,
            'resolutions' => $resolutions,
            'items' => $items,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(ClassificationItem $it, bool $full): array
    {
        $data = [
            'id' => $it->id,
            'batch' => $it->batch,
            'source_text' => $it->source_text,
            'kind' => $it->kind,
            'resolution' => $it->resolution,
            'final_code' => $it->final_code,
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
            'matched_code' => $r->matched_code,
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
