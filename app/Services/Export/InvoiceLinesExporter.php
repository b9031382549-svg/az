<?php

namespace App\Services\Export;

use App\Models\ClassificationItem;
use App\Models\EInvoice;
use App\Models\ImportBatch;
use App\Models\RubricatorNode;
use App\Services\Classify\AnswerReliability;
use App\Services\Classify\DecisionSummary;
use App\Services\Classify\HeadingMatch;
use App\Services\Import\InvoiceLinesImporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Invoice lines back as an .xlsx: every column of the line-level export, as it came in, then our
 * answer for the line's item — code, category, good/service, status, how it was found, how far
 * to trust it, whether it matches the code the supplier declared — the upload and a link to the
 * decision. One row per LINE (the review export has one per name), so a file comes back as it
 * went in, with our answer beside every line; the export's columns read back as the export.
 *
 * Written with OpenSpout in constant memory. A part holds at most uploads.export_part_lines
 * lines: an .xlsx goes out only once it is zipped (100k lines ≈ 24 s, 700k ≈ 230 s, measured),
 * and nginx gives a request 120 s — a bigger selection is offered in parts.
 */
class InvoiceLinesExporter
{
    /** Lines read, and their items looked up, at a time. */
    private const CHUNK = 2000;

    /** @var array<string, string> 4-digit heading (or "99") => its name in the UI language */
    private array $headingNames = [];

    /** @var array<string, string> upload key => its file name */
    private array $uploadLabels = [];

    public function __construct(
        private readonly DecisionSummary $summary,
        private readonly AnswerReliability $reliability,
    ) {}

    public static function partLines(): int
    {
        return max(1, (int) config('uploads.export_part_lines'));
    }

    /** How many files a selection of $lines lines is written in. */
    public static function parts(int $lines): int
    {
        return max(1, (int) ceil($lines / self::partLines()));
    }

    /**
     * Write part $part (1-based, in line-id order) of the selected lines to $output — a path, or
     * php://output. Returns the number of lines written.
     *
     * @param  Builder<EInvoice>  $lines  the selection, unordered
     */
    public function write(Builder $lines, string $output, int $part = 1): int
    {
        $size = self::partLines();
        // The part as an id range — parts never overlap or skip a line.
        $first = (clone $lines)->orderBy('id')->offset(($part - 1) * $size)->value('id');
        $last = $first === null ? null : (clone $lines)->orderBy('id')->offset($part * $size - 1)->value('id');

        // One width per column — overlapping ranges make Excel "repair" the file.
        $options = new Options;
        foreach ([[20, 1, 11], [48, 12, 13], [16, 14, 24], [12, 25, 25], [36, 26, 26], [14, 27, 27],
            [18, 28, 28], [24, 29, 29], [12, 30, 30], [14, 31, 31], [30, 32, 32], [40, 33, 33]] as [$width, $from, $to]) {
            $options->setColumnWidthForRange($width, $from, $to);
        }
        $writer = new Writer($options);
        $writer->openToFile($output);
        $writer->getCurrentSheet()->setName('Şablon');
        $writer->addRow(Row::fromValuesWithStyle($this->header(), (new Style)->withFontBold(true)));

        $written = 0;
        if ($first !== null) {
            (clone $lines)->toBase()
                ->where('id', '>=', $first)
                ->when($last !== null, fn ($q) => $q->where('id', '<=', $last))
                ->chunkById(self::CHUNK, function (Collection $chunk) use ($writer, &$written) {
                    foreach ($this->rows($chunk) as $values) {
                        $writer->addRow(Row::fromValues($values));
                        $written++;
                    }
                });
        }
        $writer->close();

        return $written;
    }

    /** @return array<int, string> the export's own headers, then ours in the UI language */
    private function header(): array
    {
        return [
            ...array_values(InvoiceLinesImporter::headers()),
            __('Our code'), __('Category'), __('Good or service'), __('Status'), __('Method'),
            __('Reliability, %'), __('Matches the declared code'), __('Upload'), __('Decision page'),
        ];
    }

    /**
     * @param  Collection<int, object>  $lines  e_invoices rows
     * @return array<int, array<int, mixed>>
     */
    private function rows(Collection $lines): array
    {
        $items = ClassificationItem::whereIn('id', $lines->pluck('classification_item_id')->filter()->unique()->values())
            ->get(['id', 'resolution', 'final_code', 'kind', 'confirmed_at'])
            ->keyBy('id');
        $methods = $this->summary->methodsFor($items->values());
        $grounded = $this->reliability->groundedWebAnswers($items->values(), $methods);
        $this->remember($items, $lines);

        $rows = [];
        foreach ($lines as $line) {
            $item = $items[$line->classification_item_id] ?? null;
            $method = $item !== null ? $methods[$item->id]['method'] : null;
            $rows[] = [...$this->exportColumns($line), ...$this->ourColumns($line, $item, $method, isset($grounded[$item?->id ?? 0]))];
        }

        return $rows;
    }

    /**
     * The line's own columns, in the export's order: codes and VÖENs stay text (leading zeros
     * survive), amounts are numbers, dates read as the export writes them (dd.mm.yyyy).
     *
     * @return array<int, mixed>
     */
    private function exportColumns(object $line): array
    {
        $values = [];
        foreach (InvoiceLinesImporter::columns() as $column) {
            $value = $line->{$column} ?? null;
            $values[] = match (true) {
                $value === null => null,
                in_array($column, ['invoice_date', 'approval_date'], true) => date('d.m.Y', (int) strtotime((string) $value)),
                is_numeric($value) && in_array($column, InvoiceLinesImporter::NUMERIC_COLUMNS, true) => (float) $value,
                default => (string) $value,
            };
        }

        return $values;
    }

    /** @return array<int, mixed> our answer for the line's item */
    private function ourColumns(object $line, ?ClassificationItem $item, ?string $method, bool $grounded): array
    {
        $status = $this->status($item, $method);
        $label = $line->import_batch !== null ? ($this->uploadLabels[$line->import_batch] ?? '') : '';
        if ($status === null) {     // a line without an item name (the legacy invoice list)
            return [null, null, null, null, null, null, null, $label, null];
        }

        $answered = $status === 'classified';
        $code = $answered ? (string) $item->final_code : '';
        $service = $answered && HeadingMatch::isService($item->kind, $code);
        $similarity = in_array($status, ['classified', 'trash'], true) ? $this->reliability->similarity((string) $method, $grounded) : null;

        return [
            $code !== '' ? $code : null,
            $code !== '' ? ($this->headingNames[mb_substr($code, 0, 4)] ?? null) : null,
            match (true) {
                $status === 'trash' => __('not a product'),
                $answered => $service ? __('service') : __('good'),
                default => null,
            },
            match ($status) {
                'classified' => __('Classified'),
                'trash' => __('Not a product'),
                'rejected' => __('Rejected'),
                'in_progress' => __('In progress'),
                default => __('Needs review'),
            },
            DecisionSummary::label((string) $method),
            $similarity !== null ? (int) round($similarity * 100) : null,
            $answered ? $this->matchesDeclared((string) $line->declared_code, $code, $service) : null,
            $label,
            route('review.decision', ['item' => $item->id]),
        ];
    }

    /**
     * Where the line's classification stands — the chat's ai_status: answered, set aside as no
     * product, rejected by a human, still with the automation, or waiting for a human.
     */
    private function status(?ClassificationItem $item, ?string $method): ?string
    {
        return match (true) {
            $item === null => null,
            in_array($item->resolution, ['agreed', 'ai_resolved', 'confirmed'], true) => 'classified',
            in_array($item->resolution, ['trash', 'rejected'], true) => $item->resolution,
            $method === 'in_progress' => 'in_progress',
            default => 'needs_review',
        };
    }

    /**
     * Does our code agree with the one the supplier declared? At the 4-digit heading; a service
     * is ours "99" against the supplier's 99xx…, so any declared service code agrees with it.
     * Null when the supplier declared nothing.
     */
    private function matchesDeclared(string $declared, string $code, bool $service): ?string
    {
        if ($declared === '') {
            return null;
        }
        $same = $service
            ? str_starts_with($declared, '99')
            : mb_substr($declared, 0, 4) === mb_substr($code, 0, 4);

        return $same ? __('yes') : __('no');
    }

    /**
     * Look up, once per export, the names this chunk needs: its answers' headings and its uploads.
     *
     * @param  Collection<int, ClassificationItem>  $items
     * @param  Collection<int, object>  $lines
     */
    private function remember(Collection $items, Collection $lines): void
    {
        $headings = $items->pluck('final_code')->filter()->map(fn ($c) => mb_substr((string) $c, 0, 4))
            ->unique()->reject(fn ($c) => isset($this->headingNames[$c]))->values();
        if ($headings->isNotEmpty()) {
            RubricatorNode::whereIn('code', $headings)->get(['code', 'title', 'title_en', 'title_ru'])
                ->each(fn (RubricatorNode $node) => $this->headingNames[(string) $node->code] = $node->localizedTitle());
        }

        $uploads = $lines->pluck('import_batch')->filter()->unique()
            ->reject(fn ($key) => isset($this->uploadLabels[$key]))->values();
        if ($uploads->isNotEmpty()) {
            ImportBatch::whereIn('key', $uploads)->get(['key', 'label'])
                ->each(fn (ImportBatch $batch) => $this->uploadLabels[(string) $batch->key] = (string) $batch->label);
        }
    }
}
