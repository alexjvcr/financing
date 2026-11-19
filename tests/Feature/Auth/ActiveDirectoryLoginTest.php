<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\ActiveDirectoryAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ActiveDirectoryLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_login_page_shows_the_form_when_there_is_no_windows_session(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('name="username"', false);
    }

    public function test_windows_session_user_is_signed_in_automatically(): void
    {
        $this->withServerVariables(['REMOTE_USER' => 'CORP\\jperez'])
            ->get(route('login'))
            ->assertRedirect(route('dashboard'));

        $user = User::where('email', 'jperez@corp.obgroup.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
    }

    public function test_windows_session_from_another_domain_is_ignored(): void
    {
        $this->withServerVariables(['REMOTE_USER' => 'OTHERPC\\admin'])
            ->get(route('login'))
            ->assertOk();

        $this->assertGuest();
    }

    public function test_windows_sso_can_be_disabled(): void
    {
        config(['ldap.windows_sso' => false]);

        $this->withServerVariables(['REMOTE_USER' => 'CORP\\jperez'])
            ->get(route('login'))
            ->assertOk();

        $this->assertGuest();
    }

    public function test_valid_active_directory_credentials_sign_the_user_in(): void
    {
        $this->mockAuthenticate([
            'username' => 'jperez',
            'name' => 'Juan Perez',
            'email' => 'jperez@corp.obgroup.com',
        ]);

        $this->post(route('login'), ['username' => 'CORP\\jperez', 'password' => 'secret'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs(User::where('email', 'jperez@corp.obgroup.com')->firstOrFail());
        $this->assertDatabaseHas('users', ['email' => 'jperez@corp.obgroup.com', 'name' => 'Juan Perez']);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->mockAuthenticate(null);

        $this->from(route('login'))
            ->post(route('login'), ['username' => 'jperez', 'password' => 'wrong'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_unreachable_directory_shows_an_error_instead_of_crashing(): void
    {
        Log::spy();

        $this->mock(ActiveDirectoryAuthenticator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('authenticate')->andThrow(new RuntimeException('down'));
        });

        $this->from(route('login'))
            ->post(route('login'), ['username' => 'jperez', 'password' => 'secret'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_username_and_password_are_required(): void
    {
        $this->post(route('login'), [])->assertSessionHasErrors(['username', 'password']);
    }

    public function test_logout_does_not_sign_the_windows_user_back_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->withServerVariables(['REMOTE_USER' => 'CORP\\jperez'])
            ->get(route('login'))
            ->assertOk();

        $this->assertGuest();
    }

    /**
     * @param  array{username: string, name: string, email: string}|null  $profile
     */
    protected function mockAuthenticate(?array $profile): void
    {
        $this->mock(ActiveDirectoryAuthenticator::class, function (MockInterface $mock) use ($profile): void {
            $mock->shouldReceive('authenticate')->andReturn($profile);
        });
    }
}
