<?php

namespace Tests\Feature\Api;

use App\Livewire\ApiTokens;
use App\Models\User;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\TestCase;

// API tokens on the Settings page: a token is issued with the abilities ticked, shown in plain
// text exactly once (only its hash is stored) and can be revoked by any user.
class ApiTokensTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_settings_page_shows_the_tokens_block(): void
    {
        $this->get('/settings')->assertOk()->assertSeeLivewire(ApiTokens::class)->assertSee('API tokens');
    }

    public function test_creates_a_token_shown_once_with_only_its_hash_stored(): void
    {
        $component = Livewire::test(ApiTokens::class)
            ->set('name', '  Client integration ')
            ->set('abilities', [ApiAbilities::CLASSIFY])
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('name', '');

        $plain = $component->get('plainToken');
        $this->assertNotNull($plain);

        $token = PersonalAccessToken::sole();
        $this->assertSame('Client integration', $token->name);
        $this->assertSame([ApiAbilities::CLASSIFY], $token->abilities);
        $this->assertTrue($token->tokenable->is($this->user));
        $this->assertSame(hash('sha256', explode('|', $plain, 2)[1]), $token->token);
        $this->assertTrue(PersonalAccessToken::findToken($plain)->is($token));

        $this->assertDatabaseHas('activity_log', ['action' => 'api_token.created', 'subject_id' => $token->id]);
    }

    public function test_new_token_form_ticks_every_ability(): void
    {
        Livewire::test(ApiTokens::class)->assertSet('abilities', ApiAbilities::all());
    }

    public function test_requires_a_name_and_a_known_ability(): void
    {
        Livewire::test(ApiTokens::class)
            ->set('name', '')
            ->set('abilities', [])
            ->call('create')
            ->assertHasErrors(['name' => 'required', 'abilities' => 'required']);

        Livewire::test(ApiTokens::class)
            ->set('name', 'x')
            ->set('abilities', ['admin'])
            ->call('create')
            ->assertHasErrors(['abilities.0' => 'in']);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_any_user_can_delete_any_token(): void
    {
        $token = User::factory()->create()->createToken('theirs', [ApiAbilities::RESULTS])->accessToken;

        Livewire::test(ApiTokens::class)
            ->assertSee('theirs')
            ->call('delete', $token->id)
            ->assertDontSee('theirs');

        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertDatabaseHas('activity_log', ['action' => 'api_token.deleted', 'subject_id' => $token->id]);
    }

    public function test_dismissing_hides_the_plain_token(): void
    {
        Livewire::test(ApiTokens::class)
            ->set('name', 'once')
            ->call('create')
            ->call('dismissToken')
            ->assertSet('plainToken', null);
    }
}
