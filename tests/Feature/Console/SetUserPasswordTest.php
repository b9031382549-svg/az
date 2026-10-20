<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// user:password replaces the seeded admin / admin on a fresh install.
class SetUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_the_password_of_an_existing_login(): void
    {
        $user = User::factory()->create(['email' => 'admin']);

        $this->artisan('user:password', ['login' => 'admin'])
            ->expectsQuestion('New password', 'correct horse battery')
            ->expectsOutput('Password set for admin.')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('correct horse battery', $user->fresh()->password));
    }

    public function test_refuses_a_short_password(): void
    {
        $user = User::factory()->create(['email' => 'admin']);
        $before = $user->password;

        $this->artisan('user:password', ['login' => 'admin'])
            ->expectsQuestion('New password', 'admin')
            ->assertExitCode(1);

        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_refuses_an_unknown_login(): void
    {
        $this->artisan('user:password', ['login' => 'nobody'])->assertExitCode(1);
    }
}
