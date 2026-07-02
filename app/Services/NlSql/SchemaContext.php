<?php

namespace App\Services\NlSql;

use App\Models\MetadataCatalogEntry;
use Illuminate\Support\Facades\DB;

class SchemaContext
{
    /**
     * The only tables natural-language querying may reference. Single source of
     * truth (config) — also used by SqlGuard and the read-only role grants, so
     * system tables (users, jobs, sessions, …) are never exposed.
     *
     * @return array<int, string>
     */
    public function allowedTables(): array
    {
        return array_values((array) config('nlsql.tables', []));
    }

    /**
     * Describe the allowed tables for the LLM: the REAL structure is read from
     * the database (information_schema) so it always matches whatever table is
     * loaded, enriched with business concepts/synonyms from the metadata catalog
     * where available.
     */
    public function describe(): string
    {
        $tables = $this->allowedTables();
        if (empty($tables)) {
            return '(no tables are exposed for querying)';
        }

        $columns = $this->columnsFromDatabase($tables);
        $enrichment = $this->enrichmentFromCatalog($tables);

        $lines = [];
        foreach ($tables as $table) {
            if (empty($columns[$table])) {
                continue; // table not present in the DB — skip
            }
            $lines[] = "Table {$table}:";
            foreach ($columns[$table] as $column => $type) {
                $meta = $enrichment[$table][$column] ?? null;
                $desc = $meta['description'] ?? ($meta['business_concept'] ?? null);
                $aliases = ! empty($meta['aliases']) ? ' (aka: '.implode(', ', $meta['aliases']).')' : '';
                $lines[] = $desc
                    ? sprintf('  - %s [%s] — %s%s', $column, $type, $desc, $aliases)
                    : sprintf('  - %s [%s]', $column, $type);
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Real columns + types straight from the database, restricted to the
     * allow-listed tables in the public schema.
     *
     * @param  array<int, string>  $tables
     * @return array<string, array<string, string>> table => [column => type]
     */
    private function columnsFromDatabase(array $tables): array
    {
        $placeholders = implode(',', array_fill(0, count($tables), '?'));

        $rows = DB::select(
            "SELECT table_name, column_name, data_type
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name IN ({$placeholders})
             ORDER BY table_name, ordinal_position",
            $tables,
        );

        $out = [];
        foreach ($rows as $row) {
            // Hide bookkeeping columns from the AI.
            if (in_array($row->column_name, ['id', 'created_at', 'updated_at'], true)) {
                continue;
            }
            $out[$row->table_name][$row->column_name] = $this->simplifyType($row->data_type);
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<string, array<string, array{description: ?string, business_concept: string, aliases: ?array}>>
     */
    private function enrichmentFromCatalog(array $tables): array
    {
        $entries = MetadataCatalogEntry::query()
            ->where('is_active', true)
            ->whereIn('table_name', $tables)
            ->whereNotNull('column_name')
            ->get();

        $out = [];
        foreach ($entries as $e) {
            $out[$e->table_name][$e->column_name] = [
                'description' => $e->description,
                'business_concept' => $e->business_concept,
                'aliases' => $e->aliases,
            ];
        }

        return $out;
    }

    private function simplifyType(string $type): string
    {
        return match (true) {
            str_contains($type, 'character'), str_contains($type, 'text') => 'string',
            str_contains($type, 'timestamp') => 'datetime',
            $type === 'date' => 'date',
            str_contains($type, 'numeric'), str_contains($type, 'double'), str_contains($type, 'real') => 'decimal',
            str_contains($type, 'int') => 'integer',
            str_contains($type, 'bool') => 'boolean',
            default => $type,
        };
    }
}
