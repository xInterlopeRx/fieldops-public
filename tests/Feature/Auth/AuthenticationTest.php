<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_application_head_links_fieldops_favicon_assets(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee('rel="icon" type="image/svg+xml" href="'.asset('favicons/fieldops/favicon.svg').'"', escape: false)
            ->assertSee('rel="icon" type="image/png" sizes="16x16" href="'.asset('favicons/fieldops/favicon-16x16.png').'"', escape: false)
            ->assertSee('rel="icon" type="image/png" sizes="32x32" href="'.asset('favicons/fieldops/favicon-32x32.png').'"', escape: false)
            ->assertSee('rel="icon" type="image/png" sizes="48x48" href="'.asset('favicons/fieldops/favicon-48x48.png').'"', escape: false)
            ->assertSee('rel="shortcut icon" href="'.asset('favicon.ico').'"', escape: false)
            ->assertSee('rel="apple-touch-icon" sizes="180x180" href="'.asset('favicons/fieldops/apple-touch-icon.png').'"', escape: false)
            ->assertSee('rel="manifest" href="'.asset('favicons/fieldops/site.webmanifest').'"', escape: false)
            ->assertSee('<meta name="theme-color" content="#0B172A">', escape: false);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
