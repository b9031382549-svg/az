<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'lines_deleted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function classifications(): HasMany
    {
        return $this->hasMany(Classification::class, 'batch', 'key');
    }

    /** Items classified under this upload (the multi-mechanism model). */
    public function items(): HasMany
    {
        return $this->hasMany(ClassificationItem::class, 'batch', 'key');
    }

    /** Invoice rows (e_invoices) this upload brought in — invoice uploads only. */
    public function lines(): HasMany
    {
        return $this->hasMany(EInvoice::class, 'import_batch', 'key');
    }
}
