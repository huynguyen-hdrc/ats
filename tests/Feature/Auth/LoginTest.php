<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email, string $password = 'password-123', array $extra = [])
    {
        return $this->postJson('/login', ['email' => $email, 'password' => $password] + $extra);
    }

    public function test_active_user_logs_in_and_reaches_api(): void
    {
        $u = User::factory()->role('hr')->create();

        $this->login($u->email)->assertSuccessful();
        $this->assertAuthenticatedAs($u);
        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('user.email', $u->email)
            ->assertJsonPath('role.base', 'hr')
            ->assertJsonPath('perms.candidates.view', true);

        $this->assertNotNull($u->fresh()->last_login_at);
        $this->assertTrue(AuditLog::where('action', 'auth.login')->where('user_id', $u->id)->exists());
    }

    public function test_email_is_case_insensitive(): void
    {
        $u = User::factory()->create(['email' => 'an.nguyen@helidirect.com']);
        $this->login('An.Nguyen@Helidirect.com')->assertSuccessful();
        $this->assertAuthenticatedAs($u);
    }

    public function test_wrong_password_fails_with_generic_message(): void
    {
        $u = User::factory()->create();
        $this->login($u->email, 'wrong-password')->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'auth.login_failed')->exists());
    }

    public function test_invited_and_locked_users_cannot_log_in(): void
    {
        $locked = User::factory()->locked()->create();
        $this->login($locked->email)->assertStatus(422);
        $invited = User::factory()->invited()->create();
        $this->login($invited->email)->assertStatus(422);
        $this->assertGuest();
    }

    public function test_five_failures_block_further_attempts_even_with_right_password(): void
    {
        $u = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->login($u->email, 'nope-nope-nope');
        }
        $this->login($u->email)->assertStatus(422)->assertJsonFragment(['Sai mật khẩu quá nhiều lần. Thử lại sau 15 phút.']);
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'auth.lockout')->exists());
    }

    public function test_successful_login_resets_failure_counter(): void
    {
        $u = User::factory()->create();
        for ($i = 0; $i < 4; $i++) {
            $this->login($u->email, 'nope-nope-nope');
        }
        $this->login($u->email)->assertSuccessful();
        $this->postJson('/logout');
        for ($i = 0; $i < 4; $i++) {
            $this->login($u->email, 'nope-nope-nope');
        }
        $this->login($u->email)->assertSuccessful();
    }

    public function test_guest_gets_401_from_api(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_user_locked_mid_session_is_cut_off(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->getJson('/api/me')->assertOk();
        $u->forceFill(['status' => 'locked'])->save();
        $this->getJson('/api/me')->assertUnauthorized()->assertJsonPath('code', 'account_inactive');
    }

    public function test_admin_without_two_factor_is_limited_to_me(): void
    {
        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin)->getJson('/api/me')->assertOk()->assertJsonPath('two_factor_required', true);
        $this->getJson('/api/users')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    }

    public function test_admin_with_two_factor_gets_challenge_on_login(): void
    {
        $admin = User::factory()->admin()->create();
        $this->login($admin->email)->assertOk()->assertJson(['two_factor' => true]);
        $this->assertGuest();
    }
}
