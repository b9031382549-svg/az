<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** The health endpoint boots the framework and returns 200. */
    public function test_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertStatus(200);
    }

    /** The app is auth-gated: guests hitting "/" are redirected to login. */
    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
