<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The API reference page: behind the login like every page, and built from the running code
// and config — the address, limits, optional fields, similarity values and version on it
// change with the config, so it cannot drift from the API it describes.
class ApiDocsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_needs_a_login(): void
    {
        $this->get('/api-docs')->assertRedirect('/login');
    }

    public function test_describes_every_route(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/api-docs')
            ->assertOk()
            ->assertSeeText('API классификатора')
            ->assertSee('/api/classify')
            ->assertSee('/api/classify/{request_id}', false)
            ->assertSee('/api/health-check')
            ->assertSee('/api/version')
            ->assertSee('/api/results/{item}', false)
            ->assertSee('/api/uploads/{batch}', false)
            ->assertSee('Authorization: Bearer', false)
            ->assertSee(url('/api/classify'), false); // samples use this installation's address
    }

    public function test_reads_limits_fields_similarity_and_version_from_the_config(): void
    {
        $this->actingAs(User::factory()->create());
        config()->set('api.classify.max_items', 12345);
        config()->set('api.classify.page_size', 777);
        config()->set('api.similarity.consensus', 0.91);
        config()->set('api.model.version', '9.8.7');

        $this->get('/api-docs')
            ->assertOk()
            ->assertSeeText('до 12 345 позиций в запросе')
            ->assertSee('limit=777', false)
            ->assertSee('0.91')
            ->assertSee('9.8.7')
            ->assertSee('supplier_tin')
            ->assertSee('total_amount');
    }

    public function test_the_sidebar_and_the_tokens_block_link_to_it(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings')->assertOk()->assertSee(route('api-docs'), false)->assertSeeText('How to call the API');
    }
}
