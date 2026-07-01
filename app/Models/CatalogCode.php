<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogCode extends Model
{
    protected $table = 'catalog';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'embedded_at' => 'datetime',
        ];
    }

    public function isService(): bool
    {
        return $this->kind === 'service';
    }

    /**
     * The catalog name for the active UI locale, falling back to the base
     * Azerbaijani name when a translation is missing. Retrieval always uses the
     * base `name`; this is display-only.
     */
    public function localizedName(): string
    {
        $name = match (app()->getLocale()) {
            'en' => $this->name_en,
            'ru' => $this->name_ru,
            default => null, // az is the base
        };

        return ($name !== null && $name !== '') ? $name : (string) $this->name;
    }
}
