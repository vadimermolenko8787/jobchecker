<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/vacancies')->assertRedirect(route('login'));
        $this->post('/run')->assertRedirect(route('login'));
    }

    public function test_login_page_renders_without_sidebar(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="password"', false)->assertDontSee('side-run-btn');
    }

    public function test_user_logs_in_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        // Even the right password is refused until the lockout passes.
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_user_create_command_creates_and_resets_password(): void
    {
        $this->artisan('user:create', ['email' => 'me@example.com'])
            ->expectsQuestion('Password', 'first-pass')
            ->assertSuccessful();
        $this->artisan('user:create', ['email' => 'me@example.com'])
            ->expectsQuestion('Password', 'second-pass')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->post('/login', ['email' => 'me@example.com', 'password' => 'second-pass']);
        $this->assertAuthenticated();
    }

    public function test_user_create_rejects_short_password(): void
    {
        $this->artisan('user:create', ['email' => 'me@example.com'])
            ->expectsQuestion('Password', 'short')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_locale_switch_is_stored_globally(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from('/')->post('/locale', ['locale' => 'en'])->assertRedirect('/');
        $this->assertSame('en', Setting::get('locale'));

        $this->post('/locale', ['locale' => 'de'])->assertSessionHasErrors('locale');
        $this->assertSame('en', Setting::get('locale'));
    }

    public function test_guest_cannot_switch_locale(): void
    {
        $this->post('/locale', ['locale' => 'en'])->assertRedirect(route('login'));
        $this->assertNull(Setting::query()->find('locale'));
    }
}
