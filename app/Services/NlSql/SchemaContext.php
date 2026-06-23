<?php

namespace App\Services\NlSql;

use App\Models\MetadataCatalogEntry;
use Illuminate\Support\Collection;

class SchemaContext
{
    /**
     * Tables the NL->SQL feature is allowed to query. Must match the grants on
     * the read-only role (see the create_readonly_role migration).
     *
     * @return array<int, string>
     */
    public function allowedTables(): array
    {
        return MetadataCatalogEntry::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('table_name')
            ->all();
    }

    /**
     * Render the catalog as a compact schema description the LLM can ground on.
     */
    public function describe(): string
    {
        $byTable = MetadataCatalogEntry::query()
            ->where('is_active', true)
            ->orderBy('table_name')
            ->orderBy('id')
            ->get()
            ->groupBy('table_name');

        $lines = [];
        foreach ($byTable as $table => $entries) {
            $lines[] = "Table {$table}:";
            /** @var Collection<int, MetadataCatalogEntry> $entries */
            foreach ($entries as $e) {
                if (! $e->column_name) {
                    continue;
                }
                $aliases = $e->aliases ? ' (aka: '.implode(', ', $e->aliases).')' : '';
                $lines[] = sprintf(
                    '  - %s [%s, %s] — %s%s',
                    $e->column_name,
                    $e->data_type,
                    $e->role,
                    $e->description ?? $e->business_concept,
                    $aliases,
                );
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }
}
