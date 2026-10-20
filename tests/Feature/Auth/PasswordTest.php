<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\InviteUser;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_responds_the_same_for_unknown_email(): void
    {
        Notification::fake();
        $u = User::factory()->create();

        $known = $this->postJson('/forgot-password', ['email' => $u->email]);
        $unknown = $this->postJson('/forgot-password', ['email' => 'nobody@helidirect.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentTo($u, ResetPassword::class);
    }

    public function test_reset_password_sets_new_password_and_activates_invited_user(): void
    {
        $u = User::factory()->invited()->create();
        $token = Password::broker()->createToken($u);

        $this->postJson('/reset-password', [
            'token' => $token, 'email' => $u->email,
            'password' => 'new-strong-pass-1', 'password_confirmation' => 'new-strong-pass-1',
        ])->assertOk();

        $u->refresh();
        $this->assertSame('active', $u->status);
        $this->assertNotNull($u->password_changed_at);
        $this->postJson('/login', ['email' => $u->email, 'password' => 'new-strong-pass-1'])->assertSuccessful();
    }

    public function test_reset_does_not_unlock_locked_user(): void
    {
        $u = User::factory()->locked()->create();
        $token = Password::broker()->createToken($u);
        $this->postJson('/reset-password', [
            'token' => $token, 'email' => $u->email,
            'password' => 'new-strong-pass-1', 'password_confirmation' => 'new-strong-pass-1',
        ])->assertOk();
        $this->assertSame('locked', $u->fresh()->status);
    }

    public function test_short_password_is_rejected(): void
    {
        $u = User::factory()->create();
        $token = Password::broker()->createToken($u);
        $this->postJson('/reset-password', [
            'token' => $token, 'email' => $u->email, 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_change_password_requires_current_and_revokes_other_sessions(): void
    {
        $u = User::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'other-device', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time(),
        ]);

        $this->actingAs($u)->putJson('/user/password', [
            'current_password' => 'wrong', 'password' => 'new-strong-pass-1', 'password_confirmation' => 'new-strong-pass-1',
        ])->assertStatus(422);

        $this->putJson('/user/password', [
            'current_password' => 'password-123', 'password' => 'new-strong-pass-1', 'password_confirmation' => 'new-strong-pass-1',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
    }

    public function test_invite_link_sets_password_and_activates(): void
    {
        Notification::fake();
        $u = User::factory()->invited()->create();
        $u->sendInvite();

        $token = null;
        Notification::assertSentTo($u, InviteUser::class, function (InviteUser $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->postJson('/accept-invite', [
            'token' => $token, 'email' => $u->email,
            'password' => 'first-pass-123', 'password_confirmation' => 'first-pass-123',
        ])->assertOk();
        $this->assertSame('active', $u->fresh()->status);

        // Link chỉ dùng một lần.
        $this->postJson('/accept-invite', [
            'token' => $token, 'email' => $u->email,
            'password' => 'second-pass-123', 'password_confirmation' => 'second-pass-123',
        ])->assertStatus(422);
    }

    public function test_expired_invite_is_rejected(): void
    {
        $u = User::factory()->invited()->create();
        $token = Password::broker('invites')->createToken($u);
        $this->travel(73)->hours();

        $this->postJson('/accept-invite', [
            'token' => $token, 'email' => $u->email,
            'password' => 'first-pass-123', 'password_confirmation' => 'first-pass-123',
        ])->assertStatus(422);
        $this->assertSame('invited', $u->fresh()->status);
    }
}
