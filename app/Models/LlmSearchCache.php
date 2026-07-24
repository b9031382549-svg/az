<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A cached web-search resolver answer — the slow paid `:online` call's result, keyed
 * by (model, prompt_version, source_hash) so an identical item name is answered from
 * here instead of hitting the provider again. Written only for confident, catalog-valid
 * answers. See App\Services\Classify\SearchCache and SearchResolverService.
 */
class LlmSearchCache extends Model
{
    protected $table = 'llm_search_cache';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'response' => 'array', // {content, usage, model, annotations}
        ];
    }
}
