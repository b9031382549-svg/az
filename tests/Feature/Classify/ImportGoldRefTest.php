<?php

namespace Tests\Feature\Classify;

use App\Models\GoldLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportGoldRefTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = storage_path('app/test-gold-ref-'.bin2hex(random_bytes(4)).'.csv');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function writeCsv(array $rows): void
    {
        $fh = fopen($this->path, 'w');
        fputcsv($fh, ['name', 'service', 'chapter', 'heading', 'group', 'confidence', 'source', 'note']);
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);
    }

    /**
     * Runs the import with ONLY --gold-ref active — the real start-data/gold/ivan.xlsx
     * and fedor.xlsx exist on disk in this repo, so every call must explicitly point
     * --ivan/--fedor at nothing, or those get imported too and break the row counts.
     */
    private function runImport(?string $goldRef = null): void
    {
        $this->artisan('benchmark:import-gold', [
            '--ivan' => storage_path('app/does-not-exist-ivan.xlsx'),
            '--fedor' => storage_path('app/does-not-exist-fedor.xlsx'),
            '--gold-ref' => $goldRef ?? $this->path,
        ])->assertSuccessful();
    }

    public function test_imports_a_good_and_a_service_row(): void
    {
        $this->writeCsv([
            ['Granulated Sugar 4kg', 'False', '17', '1701', 'sugar', '0.97', 'opus+gpt', 'sugar note'],
            ['Moon Light Hotel', 'True', '', '', 'hotel', '0.97', 'opus+gpt', 'hotel note'],
        ]);

        $this->runImport();

        $this->assertDatabaseCount('gold_labels', 2);
        $good = GoldLabel::where('name_key', GoldLabel::keyFor('Granulated Sugar 4kg'))->first();
        $this->assertSame('gold', $good->source);
        $this->assertSame('1701', $good->heading);
        $this->assertFalse((bool) $good->is_service);
        $this->assertSame(0.97, $good->confidence);
        $this->assertSame('opus+gpt', $good->meta['labelled_by']);

        $svc = GoldLabel::where('name_key', GoldLabel::keyFor('Moon Light Hotel'))->first();
        $this->assertTrue((bool) $svc->is_service);
        $this->assertNull($svc->heading);
    }

    public function test_a_good_with_no_heading_is_skipped(): void
    {
        $this->writeCsv([
            ['Unclassifiable Thing', 'False', '', '', '', '0.5', 'opus', 'unsure'],
        ]);

        $this->runImport();

        $this->assertDatabaseCount('gold_labels', 0);
    }

    public function test_conflicting_labels_for_the_same_name_are_both_dropped(): void
    {
        $this->writeCsv([
            ['Ambiguous Widget', 'False', '84', '8471', 'computers', '0.9', 'opus', 'first pass'],
            ['ambiguous   widget', 'False', '62', '6208', 'apparel', '0.9', 'gpt', 'second pass, disagrees'],
        ]);

        $this->runImport();

        $this->assertDatabaseCount('gold_labels', 0);
    }

    public function test_repeated_identical_labels_for_the_same_name_are_kept_once(): void
    {
        $this->writeCsv([
            ['Same Product', 'False', '84', '8471', 'computers', '0.9', 'opus', 'a'],
            ['same   product', 'False', '84', '8471', 'computers', '0.9', 'gpt', 'b'],
        ]);

        $this->runImport();

        $this->assertDatabaseCount('gold_labels', 1);
    }

    public function test_import_is_idempotent(): void
    {
        $this->writeCsv([
            ['Repeatable Item', 'False', '84', '8471', 'computers', '0.9', 'opus', 'a'],
        ]);

        $this->runImport();
        $this->runImport();

        $this->assertDatabaseCount('gold_labels', 1);
    }

    public function test_missing_file_warns_and_does_not_fail(): void
    {
        $this->runImport(storage_path('app/does-not-exist.csv'));

        $this->assertDatabaseCount('gold_labels', 0);
    }
}
