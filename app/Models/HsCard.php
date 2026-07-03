<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The distilled legal classification knowledge for one HS chapter/heading —
 * what it COVERS, INCLUDES, EXCLUDES (with reroute), and its CLOSED LIST when a
 * note is genuinely exhaustive. Authored offline from the HS notes / Explanatory
 * Notes; consumed by the broker at a fork (see BrokerDescentMechanism::decide).
 */
class HsCard extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'includes' => 'array',
            'excludes' => 'array',
            'closed_list' => 'array',
            'citations' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * A compact, structured rule block for the branch — NOT raw note prose. Only
     * the sections that exist are emitted. Product synonyms are kept multilingual
     * so the rule matches the item whatever language it arrived in.
     */
    public function promptBlock(string $indent = '    '): string
    {
        $lines = [];

        if (($this->scope ?? '') !== '') {
            $lines[] = 'COVERS: '.$this->scope;
        }

        $inc = $this->renderProducts($this->includes ?? []);
        if ($inc !== '') {
            $lines[] = 'INCLUDES: '.$inc;
        }

        foreach ($this->excludes ?? [] as $e) {
            $cls = trim((string) ($e['product_class'] ?? ''));
            $to = trim((string) ($e['reroute_code'] ?? ''));
            if ($cls !== '') {
                // $to may be a heading (4-digit), a chapter, or a section ref — so
                // "see {code}" rather than "see heading {code}".
                $lines[] = 'EXCLUDES: '.$cls.($to !== '' ? " → see {$to}" : '');
            }
        }

        $cl = $this->closed_list ?? [];
        if (! empty($cl['exhaustive']) && ! empty($cl['members'])) {
            $lines[] = 'CLOSED LIST (ONLY these belong here — anything else does NOT): '
                .implode('; ', array_map('strval', $cl['members']));
        }

        if ($lines === []) {
            return '';
        }

        return $indent.implode("\n".$indent, $lines);
    }

    /** @param array<int, array<string, mixed>> $products */
    private function renderProducts(array $products): string
    {
        $parts = [];
        foreach ($products as $p) {
            $name = trim((string) ($p['product'] ?? ''));
            if ($name === '') {
                continue;
            }
            $syn = array_filter(array_map('strval', (array) ($p['syn'] ?? [])), fn ($s) => $s !== '');
            $parts[] = $syn !== [] ? $name.' ('.implode('/', $syn).')' : $name;
        }

        return implode('; ', $parts);
    }
}
