<?php

namespace App\Services\Api;

use App\Jobs\FeedUploadClassificationJob;
use App\Jobs\IngestApiRequestJob;
use App\Models\ApiRequestName;
use App\Models\ClassificationItem;
use App\Models\ImportBatch;
use App\Models\ItemTranslation;
use App\Services\Classify\ClassificationQueue;
use App\Services\Import\BackgroundInvoiceUploads;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * POST /api/classify requests. One request is one batch (import_batches, source "api") on the
 * same pipeline as the Classify and Upload pages. Accepting only saves the body and returns the
 * request id; a worker then creates the items, and the shared feeding chain puts them on the
 * queue in portions (BackgroundInvoiceUploads::feed) — so 100k names neither hold the HTTP
 * request open nor flood Redis.
 *
 * Status: accepted (saved, not read yet) → processing (on the pipeline) → done (the automation
 * answered every name), or failed.
 */
class ClassifyRequests
{
    public const SOURCE = 'api';

    /** import_batches.status while the saved body waits for its worker. */
    public const ACCEPTED = 'accepted';

    /** Distinct names per createItems() call. */
    private const CHUNK = 1000;

    public function __construct(private readonly ClassificationQueue $queue) {}

    /**
     * What is wrong with the items, field => message (the first few only) — empty when every
     * item has a name. Everything but the name is optional.
     *
     * @param  array<int, mixed>  $items
     * @return array<string, string>
     */
    public function itemErrors(array $items): array
    {
        $max = (int) config('api.classify.max_name_length');
        $errors = [];
        foreach ($items as $i => $item) {
            $name = is_array($item) ? ($item['name'] ?? null) : null;
            if (! is_string($name) || trim($name) === '') {
                $errors["items.{$i}.name"] = 'Every item needs a name.';
            } elseif (mb_strlen($name) > $max) {
                $errors["items.{$i}.name"] = "A name can be at most {$max} characters.";
            }
            if (count($errors) >= 20) {
                break;
            }
        }

        return $errors;
    }

    /** @param  array<int, array<string, mixed>>  $items  the validated request items */
    public function accept(array $items, int $userId, string $tokenName): ImportBatch
    {
        $key = (string) Str::uuid();

        File::ensureDirectoryExists($this->directory());
        $fh = fopen($this->path($key), 'wb');
        foreach ($items as $item) {
            fwrite($fh, json_encode(['name' => (string) $item['name']], JSON_UNESCAPED_UNICODE)."\n");
        }
        fclose($fh);

        $batch = ImportBatch::create([
            'key' => $key,
            'label' => 'API · '.$tokenName,
            'source' => self::SOURCE,
            'status' => self::ACCEPTED,
            'user_id' => $userId,
            'item_count' => 0,
            'meta' => ['lines' => count($items)],
        ]);
        IngestApiRequestJob::dispatch($key);

        return $batch;
    }

    /** Create the request's items from its saved body (on the worker). Safe to re-run until it finishes. */
    public function ingest(string $key): void
    {
        $lock = Cache::lock('api-classify-ingest:'.$key, 900);
        if (! $lock->get()) {
            return;
        }

        $items = 0;
        try {
            $batch = $this->find($key);
            if ($batch === null || $batch->status !== self::ACCEPTED) {
                return;
            }
            if (! is_file($this->path($key))) {
                $this->fail($batch, 'The request body was lost before it was read. Please send it again.');

                return;
            }

            $names = $this->distinctNames($key);
            // A re-run starts the name list over; items are upserted, so nothing doubles.
            ApiRequestName::where('batch', $key)->delete();
            foreach (array_chunk($names, self::CHUNK) as $chunk) {
                $created = $this->queue->createItems(array_map('trim', $chunk), $key);
                ApiRequestName::insert(array_map(fn (string $name) => [
                    'batch' => $key,
                    'name' => $name,
                    'classification_item_id' => $created[ItemTranslation::hashFor($name)]->id,
                ], $chunk));
            }

            $items = ClassificationItem::where('batch', $key)->count();
            $batch->update([
                'status' => BackgroundInvoiceUploads::IMPORTED,
                'item_count' => $items,
                'meta' => ['names' => count($names)] + ($batch->meta ?? []),
            ]);
            @unlink($this->path($key));
        } finally {
            $lock->release();
        }

        if ($items > 0) {
            FeedUploadClassificationJob::dispatch($key);
        }
    }

    public function fail(ImportBatch $batch, string $error): void
    {
        $batch->update(['status' => BackgroundInvoiceUploads::FAILED, 'meta' => ['error' => $error] + ($batch->meta ?? [])]);
        @unlink($this->path($batch->key));
    }

    /** Re-dispatch a request whose ingest job was lost (scheduled). */
    public function tend(): void
    {
        ImportBatch::where('source', self::SOURCE)
            ->where('status', self::ACCEPTED)
            ->where('updated_at', '<', now()->subMinutes(10))
            ->each(function (ImportBatch $batch) {
                $batch->touch();
                IngestApiRequestJob::dispatch($batch->key);
            });
    }

    public function find(string $key): ?ImportBatch
    {
        return ImportBatch::where('key', $key)->where('source', self::SOURCE)->first();
    }

    /** @return array{request_id: string, status: string, lines: int, names: int, answered: int, created_at: ?string, error: ?string} */
    public function status(ImportBatch $batch): array
    {
        $names = ApiRequestName::where('batch', $batch->key)->count();
        $answered = ApiRequestName::where('api_request_names.batch', $batch->key)
            ->join('classification_items', 'classification_items.id', '=', 'api_request_names.classification_item_id')
            ->whereNotNull('classification_items.answered_at')
            ->count();

        return [
            'request_id' => $batch->key,
            'status' => match ($batch->status) {
                self::ACCEPTED => 'accepted',
                BackgroundInvoiceUploads::FAILED => 'failed',
                default => $answered === $names ? 'done' : 'processing',
            },
            'lines' => (int) ($batch->meta['lines'] ?? 0),
            'names' => $names,
            'answered' => $answered,
            'created_at' => $batch->created_at?->toIso8601String(),
            'error' => $batch->meta['error'] ?? null,
        ];
    }

    /**
     * The distinct names of the saved body exactly as sent, in the order first seen.
     *
     * @return array<int, string>
     */
    private function distinctNames(string $key): array
    {
        $seen = [];
        $fh = fopen($this->path($key), 'rb');
        while (($raw = fgets($fh)) !== false) {
            if (($raw = trim($raw)) !== '') {
                $seen[(string) json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['name']] = true;
            }
        }
        fclose($fh);

        // A digits-only name becomes an int array key — cast back (lossless for canonical ints).
        return array_map('strval', array_keys($seen));
    }

    private function directory(): string
    {
        return (string) config('uploads.directory');
    }

    private function path(string $key): string
    {
        return $this->directory().'/api-'.basename($key).'.ndjson';
    }
}
