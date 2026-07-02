<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One rubric (intermediate category) in the XİF MN tree the broker-descent
 * mechanism navigates: a 2-digit chapter, 4-digit position or 6-digit
 * subposition. Leaves (10-digit codes) live in `catalog`, reached from the
 * deepest rubric via its code prefix.
 */
class RubricatorNode extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The category title for the active UI locale, falling back to the base
     * Azerbaijani title. Display-only; navigation/matching use the base title.
     */
    public function localizedTitle(): string
    {
        $t = match (app()->getLocale()) {
            'en' => $this->title_en,
            'ru' => $this->title_ru,
            default => null,
        };

        return ($t !== null && $t !== '') ? $t : (string) $this->title;
    }

    /** @return BelongsTo<RubricatorNode, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<RubricatorNode, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /**
     * A handful of real catalog leaves under this rubric — the concrete items
     * the broker judges a branch by (not the bare title). Uses catalog's indexed
     * chapter/position/subposition columns (equality, not LIKE).
     *
     * The sample is spread EVENLY across the branch's code range, not the first N
     * by code: a broad chapter (e.g. 84 has ~950 leaves across ~85 headings) would
     * otherwise be represented only by its first heading, giving the broker a
     * skewed view of what the branch actually contains. An even stride surfaces a
     * true cross-section (first, last, and points between).
     *
     * @return Collection<int, CatalogCode>
     */
    public function sampleLeaves(int $limit = 12): Collection
    {
        $column = match ($this->level) {
            1 => 'chapter',
            2 => 'position',
            default => 'subposition',
        };

        $base = CatalogCode::query()->where($column, $this->code)->where('is_active', true);

        $codes = (clone $base)->orderBy('code')->pluck('code')->values();
        if ($limit <= 1 || $codes->count() <= $limit) {
            return (clone $base)->orderBy('code')->limit(max(1, $limit))->get(['code', 'name']);
        }

        // Evenly-spaced indices over the ordered codes, inclusive of both ends.
        $step = ($codes->count() - 1) / ($limit - 1);
        $picked = [];
        for ($i = 0; $i < $limit; $i++) {
            $picked[] = $codes[(int) round($i * $step)];
        }
        $picked = array_values(array_unique($picked));

        return CatalogCode::whereIn('code', $picked)->orderBy('code')->get(['code', 'name']);
    }
}
