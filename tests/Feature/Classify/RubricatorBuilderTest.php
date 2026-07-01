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

    public function test_build_is_idempotent(): void
    {
        $this->seedCatalog();
        $this->artisan('data:build-rubricator')->assertSuccessful();
        $first = RubricatorNode::count();
        $this->artisan('data:build-rubricator')->assertSuccessful();

        $this->assertSame($first, RubricatorNode::count());
    }
}
