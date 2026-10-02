<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Api\ClassifyRequests;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/classify accepts up to 100k items and answers at once with the request id;
 * GET /api/classify/{id} says where the request is. Needs a token with the `classify` ability.
 */
class ClassifyController extends Controller
{
    public function __construct(private readonly ClassifyRequests $requests) {}

    public function store(Request $request): JsonResponse
    {
        @ini_set('memory_limit', '1024M'); // 100k items decode to a few hundred MB of arrays

        $request->validate(['items' => ['required', 'array', 'min:1', 'max:'.(int) config('api.classify.max_items')]]);
        $items = array_values((array) $request->input('items'));
        if ($errors = $this->requests->itemErrors($items)) {
            throw ValidationException::withMessages($errors);
        }

        $token = (string) $request->user()->currentAccessToken()->name;
        $batch = $this->requests->accept($items, (int) $request->user()->id, $token);
        Audit::log('classify.api', ['batch' => $batch->key, 'lines' => count($items), 'token' => $token], $batch);

        return response()->json($this->requests->status($batch), 202, ['Location' => url('/api/classify/'.$batch->key)]);
    }

    public function show(string $id): JsonResponse
    {
        $batch = $this->requests->find($id);
        if ($batch === null) {
            return response()->json(['message' => 'No such request.'], 404);
        }

        return response()->json($this->requests->status($batch));
    }
}
