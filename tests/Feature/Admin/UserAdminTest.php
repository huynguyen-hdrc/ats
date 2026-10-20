<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\InviteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_invites_user_who_receives_mail(): void
    {
        Notification::fake();
        $hr = User::factory()->role('hr')->create();

        $res = $this->actingAs($hr)->postJson('/api/users', [
            'name' => 'Lan Trần', 'email' => 'Lan.Tran@helidirect.com', 'role_key' => 'staff',
        ])->assertCreated()->assertJsonPath('data.status', 'invited')->assertJsonPath('invite_sent', true);

        $u = User::find($res->json('data.id'));
        $this->assertSame('lan.tran@helidirect.com', $u->email);
        $this->assertNull($u->password);
        Notification::assertSentTo($u, InviteUser::class);
        $this->assertTrue(AuditLog::where('action', 'user.invited')->where('subject_id', $u->id)->exists());
    }

    public function test_duplicate_email_rejected(): void
    {
        $hr = User::factory()->role('hr')->create();
        $this->actingAs($hr)->postJson('/api/users', ['name' => 'X', 'email' => $hr->email, 'role_key' => 'staff'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_staff_cannot_manage_users(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff)->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/users', ['name' => 'X', 'email' => 'x@helidirect.com', 'role_key' => 'staff'])->assertForbidden();
    }

    public function test_hr_cannot_grant_or_touch_admin(): void
    {
        $hr = User::factory()->role('hr')->create();
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();

        $this->actingAs($hr)->postJson('/api/users', ['name' => 'X', 'email' => 'x@helidirect.com', 'role_key' => 'admin'])->assertForbidden();
        $this->patchJson("/api/users/{$staff->id}", ['role_key' => 'admin'])->assertForbidden();
        $this->postJson("/api/users/{$admin->id}/lock")->assertForbidden();
    }

    public function test_cannot_change_own_role_or_lock_self(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->patchJson("/api/users/{$admin->id}", ['role_key' => 'hr'])->assertStatus(422);
        $this->postJson("/api/users/{$admin->id}/lock")->assertStatus(422);
    }

    public function test_admins_can_lock_and_demote_each_other_while_another_admin_remains(): void
    {
        $a1 = User::factory()->admin()->create();
        $a2 = User::factory()->admin()->create();
        $this->actingAs($a1)->postJson("/api/users/{$a2->id}/lock")->assertOk();
        $this->postJson("/api/users/{$a2->id}/unlock")->assertOk();
        $this->actingAs($a2->fresh())->patchJson("/api/users/{$a1->id}", ['role_key' => 'hr'])->assertOk();
        $this->assertSame('hr', $a1->fresh()->role_key);
        // a2 giờ là admin cuối cùng: tự khoá hay tự hạ quyền đều bị chặn.
        $this->postJson("/api/users/{$a2->id}/lock")->assertStatus(422);
        $this->patchJson("/api/users/{$a2->id}", ['role_key' => 'hr'])->assertStatus(422);
    }

    public function test_lock_revokes_sessions_and_unlock_restores_status(): void
    {
        $admin = User::factory()->admin()->create();
        $u = User::factory()->create();
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)->postJson("/api/users/{$u->id}/lock")->assertOk()->assertJsonPath('data.status', 'locked');
        $this->assertDatabaseMissing('sessions', ['id' => 's1']);
        $this->postJson("/api/users/{$u->id}/unlock")->assertOk()->assertJsonPath('data.status', 'active');

        $inv = User::factory()->invited()->locked()->create();
        $this->postJson("/api/users/{$inv->id}/unlock")->assertOk()->assertJsonPath('data.status', 'invited');
    }

    public function test_resend_invite_only_for_invited(): void
    {
        Notification::fake();
        $hr = User::factory()->role('hr')->create();
        $active = User::factory()->create();
        $inv = User::factory()->invited()->create();
        $this->actingAs($hr)->postJson("/api/users/{$active->id}/resend-invite")->assertStatus(422);
        $this->postJson("/api/users/{$inv->id}/resend-invite")->assertOk();
        Notification::assertSentTo($inv, InviteUser::class);
    }

    public function test_only_admin_resets_two_factor(): void
    {
        $hr = User::factory()->role('hr')->create();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->admin()->create();
        $this->actingAs($hr)->postJson("/api/users/{$target->id}/reset-two-factor")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/users/{$target->id}/reset-two-factor")->assertOk()->assertJsonPath('data.two_factor', false);
    }
}
