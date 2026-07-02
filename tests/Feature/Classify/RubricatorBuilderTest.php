<?php

namespace Tests\Feature\Classify;

use App\Models\CatalogCode;
use App\Models\RubricatorNode;
use App\Support\HsChapters;
use App\Support\ServiceRubrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RubricatorBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(): void
    {
        CatalogCode::create(['code' => '8471300000', 'kind' => 'good', 'chapter' => '84', 'position' => '8471', 'subposition' => '847130', 'is_active' => true,
            'name' => 'Hesablayıcı maşınlar:– portativ']);
        CatalogCode::create(['code' => '8471410000', 'kind' => 'good', 'chapter' => '84', 'position' => '8471', 'subposition' => '847141', 'is_active' => true,
            'name' => 'Hesablayıcı maşınlar:– digər:– – blok']);
        CatalogCode::create(['code' => '9946111100', 'kind' => 'service', 'chapter' => '99', 'position' => '9946', 'subposition' => '994611', 'is_active' => true,
            'name' => 'Diri heyvanların topdansatışı üzrə xidmətlər']);
    }

    public function test_builds_a_prefix_tree_with_derived_and_reference_titles(): void
    {
        $this->seedCatalog();

        $this->artisan('data:build-rubricator')->assertSuccessful();

        // Chapter (goods) — title from the HS reference list.
        $ch = RubricatorNode::where('code', '84')->first();
        $this->assertNotNull($ch);
        $this->assertSame(1, $ch->level);
        $this->assertSame('good', $ch->kind);
        $this->assertNull($ch->parent_id);
        $this->assertSame(HsChapters::AZ['84'], $ch->title);

        // Position (goods) — title derived from the name breadcrumb, parented to chapter.
        $pos = RubricatorNode::where('code', '8471')->first();
        $this->assertSame(2, $pos->level);
        $this->assertSame($ch->id, $pos->parent_id);
        $this->assertSame('Hesablayıcı maşınlar', $pos->title);

        // Subposition parented to position.
        $sub = RubricatorNode::where('code', '847130')->first();
        $this->assertSame(3, $sub->level);
        $this->assertSame($pos->id, $sub->parent_id);
    }

    public function test_services_get_a_root_and_authored_and_derived_titles(): void
    {
        $this->seedCatalog();
        $this->artisan('data:build-rubricator')->assertSuccessful();

        $root = RubricatorNode::where('code', '99')->first();
        $this->assertSame('Xidmətlər', $root->title);
        $this->assertSame('service', $root->kind);

        // Position title comes from the hand-authored ServiceRubrics reference.
        $svcPos = RubricatorNode::where('code', '9946')->first();
        $this->assertSame('service', $svcPos->kind);
        $this->assertSame($root->id, $svcPos->parent_id);
        $this->assertSame(ServiceRubrics::POSITIONS['9946'], $svcPos->title);

        // Subposition title is derived from its own leaf name.
        $svcSub = RubricatorNode::where('code', '994611')->first();
        $this->assertSame('Diri heyvanların topdansatışı üzrə xidmətlər', $svcSub->title);
    }

    public function test_sample_leaves_reads_catalog_by_prefix(): void
    {
        $this->seedCatalog();
        $this->artisan('data:build-rubricator')->assertSuccessful();

        $leaves = RubricatorNode::where('code', '8471')->first()->sampleLeaves(5);

        $this->assertEqualsCanonicalizing(['8471300000', '8471410000'], $leaves->pluck('code')->all());
    }

    public function test_populates_localized_titles_from_catalog_and_reference_lists(): void
    {
        // Goods leaves carry en/ru breadcrumbs; the ru first segment ends in "р"
        // (bytes D1 80) — a byte-wise trim mask would slice the trailing 0x80 and
        // corrupt the string, so this doubles as a regression guard.
        CatalogCode::create(['code' => '8471300000', 'kind' => 'good', 'chapter' => '84', 'position' => '8471', 'subposition' => '847130', 'is_active' => true,
            'name' => 'Hesablayıcı maşınlar:– portativ',
            'name_en' => 'Calculating machines:– portable',
            'name_ru' => 'Счётные калькулятор:– портативные']);
        CatalogCode::create(['code' => '9946111100', 'kind' => 'service', 'chapter' => '99', 'position' => '9946', 'subposition' => '994611', 'is_active' => true,
            'name' => 'Diri heyvanların topdansatışı üzrə xidmətlər',
            'name_en' => 'Wholesale services of live animals',
            'name_ru' => 'Услуги оптовой торговли живыми животными']);

        $this->artisan('data:build-rubricator')->assertSuccessful();

        // Chapter titles come from the HS reference list in all three languages.
        $ch = RubricatorNode::where('code', '84')->first();
        $this->assertSame(HsChapters::EN['84'], $ch->title_en);
        $this->assertSame(HsChapters::RU['84'], $ch->title_ru);

        // Goods position titles are derived per-language from the catalog breadcrumb,
        // and the Cyrillic segment survives intact (ends in "р", not corrupted).
        $pos = RubricatorNode::where('code', '8471')->first();
        $this->assertSame('Calculating machines', $pos->title_en);
        $this->assertSame('Счётные калькулятор', $pos->title_ru);

        // Service position titles come from the authored ServiceRubrics reference.
        $svcPos = RubricatorNode::where('code', '9946')->first();
        $this->assertSame(ServiceRubrics::POSITIONS_EN['9946'], $svcPos->title_en);
        $this->assertSame(ServiceRubrics::POSITIONS_RU['9946'], $svcPos->title_ru);

        // localizedTitle() follows the locale and falls back to the base title.
        $this->app->setLocale('ru');
        $this->assertSame('Счётные калькулятор', $pos->localizedTitle());
        $this->app->setLocale('en');
        $this->assertSame('Calculating machines', $pos->localizedTitle());
        $pos->title_en = null;
        $this->assertSame($pos->title, $pos->localizedTitle());
    }

    public function test_sample_leaves_spread_across_the_whole_branch(): void
    {
        // 12 leaves across four headings of chapter 84.
        foreach (['8401', '8402', '8403', '8404'] as $pos) {
            foreach (['10', '20', '30'] as $suffix) {
                CatalogCode::create([
                    'code' => $pos.'0000'.$suffix, 'kind' => 'good', 'chapter' => '84',
                    'position' => $pos, 'subposition' => $pos.'00', 'is_active' => true,
                    'name' => "Machine {$pos}-{$suffix}",
                ]);
            }
        }
        $node = RubricatorNode::create(['code' => '84', 'level' => 1, 'kind' => 'good', 'is_active' => true]);

        $sample = $node->sampleLeaves(4);

        // A first-N-by-code sample would be all in 8401; an even stride spans the
        // range — includes the very first and the very last leaf.
        $codes = $sample->pluck('code');
        $this->assertSame(4, $codes->count());
        $this->assertContains('8401000010', $codes->all());
        $this->assertContains('8404000030', $codes->all());
        $headings = $codes->map(fn ($c) => substr($c, 0, 4))->unique();
        $this->assertGreaterThanOrEqual(3, $headings->count(), 'sample should span multiple headings');
    }

    public function test_sample_leaves_returns_all_when_fewer_than_limit(): void
    {
        $this->seedCatalog();
        $node = RubricatorNode::create(['code' => '8471', 'level' => 2, 'kind' => 'good', 'is_active' => true]);

        $this->assertEqualsCanonicalizing(
            ['8471300000', '8471410000'],
            $node->sampleLeaves(12)->pluck('code')->all(),
        );
    }

    public function test_build_is_idempotent(): void
    {
        $this->seedCatalog();
        $this->artisan('data:build-rubricator')->assertSuccessful();
        $first = RubricatorNode::count();
        $this->artisan('data:build-rubricator')->assertSuccessful();

        $this->assertSame($first, RubricatorNode::count());
    }
}
