<?php

use App\Models\GoldLabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Data fix that ships with sorter v2: a line that names only a document, a period, a code, a
// person, a firm, an institution or a place is trash (decided 2026-10-02). The model-made gold
// called many such lines services (payments by letter, to people) or goods, and memory answered
// them before the trash filter could. The rows listed in database/data/ (from the sorter dataset-v2
// reviews) leave memory — the live scope 0 and the parked gold pool -1 — and gold_labels, so a
// re-seed cannot bring them back; the one line that really is a service becomes a service.
// Memory holds codes only, so trash is not written anywhere: the rules and the sorter decide those
// names now. down() puts the old answers back.
return new class extends Migration
{
    public function up(): void
    {
        $data = $this->data();

        foreach ($data['answer_cache'] as $row) {
            $q = DB::table('answer_cache')
                ->where('test_dataset_id', $row['scope'])
                ->where('name_key', GoldLabel::keyFor($row['name']));
            $row['now'] === 'service'
                ? $q->update(['is_service' => true, 'heading' => null, 'updated_at' => now()])
                : $q->delete();
        }

        foreach ($data['gold_labels'] as $row) {
            $q = DB::table('gold_labels')
                ->where('source', 'gold')
                ->where('name_key', GoldLabel::keyFor($row['name']));
            $row['now'] === 'service'
                ? $q->update(['is_service' => true, 'heading' => null, 'code' => null, 'chapter' => null, 'updated_at' => now()])
                : $q->delete();
        }
    }

    public function down(): void
    {
        $data = $this->data();
        $now = now();

        foreach ($data['answer_cache'] as $row) {
            DB::table('answer_cache')->updateOrInsert(
                ['test_dataset_id' => $row['scope'], 'name_key' => GoldLabel::keyFor($row['name'])],
                ['source' => $row['source'], 'name' => $row['name'], 'heading' => $row['heading'],
                    'is_service' => $row['is_service'], 'created_at' => $now, 'updated_at' => $now],
            );
        }

        foreach ($data['gold_labels'] as $row) {
            DB::table('gold_labels')->updateOrInsert(
                ['source' => 'gold', 'name_key' => GoldLabel::keyFor($row['name'])],
                ['tier' => $row['tier'], 'name' => $row['name'], 'code' => $row['code'], 'heading' => $row['heading'],
                    'chapter' => $row['heading'] !== null ? substr($row['heading'], 0, 2) : null,
                    'is_service' => $row['is_service'], 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    /** @return array{answer_cache: array<int, array<string, mixed>>, gold_labels: array<int, array<string, mixed>>} */
    private function data(): array
    {
        return json_decode((string) file_get_contents(database_path('data/gold_trash_convention_2026_10_02.json')), true);
    }
};
