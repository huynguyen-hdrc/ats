<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermTest extends TestCase
{
    use RefreshDatabase;

    private function matrix(array $grant): array
    {
        $m = [];
        foreach (Access::MODULES as $mod => $_) {
            foreach (Access::ACTIONS as $a => $__) {
                $m[$mod][$a] = in_array("{$mod}.{$a}", $grant, true);
            }
        }

        return $m;
    }

    public function test_lists_roles_with_full_matrix(): void
    {
        $hr = User::factory()->role('hr')->create();
        $res = $this->actingAs($hr)->getJson('/api/roles')->assertOk();
        $this->assertSame(['admin', 'hr', 'manager', 'staff'], array_column($res->json('data'), 'key'));
        $this->assertTrue($res->json('data.0.perms.settings.delete')); // admin luôn đủ quyền
    }

    public function test_custom_role_inherits_base_and_uses_its_own_matrix(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/api/roles', ['key' => 'recruiter', 'label' => 'Tuyển dụng', 'base' => 'staff'])->assertCreated();
        $this->putJson('/api/roles/recruiter/perms', ['perms' => $this->matrix(['candidates.view', 'candidates.create'])])->assertOk();

        $u = User::factory()->role('recruiter')->create();
        $this->actingAs($u)->getJson('/api/me')
            ->assertJsonPath('role.base', 'staff')
            ->assertJsonPath('perms.candidates.create', true)
            ->assertJsonPath('perms.candidates.delete', false);

        $log = AuditLog::where('action', 'role.perms_updated')->latest('id')->first();
        $this->assertEqualsCanonicalizing(['candidates.view', 'candidates.create'], $log->meta['granted']);
    }

    public function test_non_admin_cannot_edit_own_role_or_admin_role(): void
    {
        $hr = User::factory()->role('hr')->create();
        $this->actingAs($hr)->putJson('/api/roles/hr/perms', ['perms' => $this->matrix([])])->assertForbidden();
        $this->putJson('/api/roles/admin/perms', ['perms' => $this->matrix([])])->assertStatus(422);
        $this->postJson('/api/roles', ['key' => 'superx', 'label' => 'X', 'base' => 'admin'])->assertForbidden();
    }

    public function test_role_in_use_or_system_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->deleteJson('/api/roles/staff')->assertStatus(422);
        $this->postJson('/api/roles', ['key' => 'temp', 'label' => 'Tạm', 'base' => 'staff'])->assertCreated();
        User::factory()->role('temp')->create();
        $this->deleteJson('/api/roles/temp')->assertStatus(422);
    }

    public function test_invalid_role_key_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/api/roles', ['key' => 'Bad Key', 'label' => 'X', 'base' => 'staff'])
            ->assertStatus(422)->assertJsonValidationErrors('key');
    }

    public function test_audit_log_listing_requires_settings_view(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff)->getJson('/api/audit-logs')->assertForbidden();
        $hr = User::factory()->role('hr')->create();
        $this->actingAs($hr)->getJson('/api/audit-logs')->assertOk()->assertJsonStructure(['data', 'total']);
    }

    public function test_create_admin_command_prints_password_once(): void
    {
        $this->artisan('hr:create-admin', ['email' => 'Quan.Tri@helidirect.com'])
            ->expectsOutputToContain('Mật khẩu:')
            ->assertSuccessful();
        $u = User::where('email', 'quan.tri@helidirect.com')->first();
        $this->assertTrue($u->isAdmin());
        $this->assertSame('active', $u->status);
        $this->artisan('hr:create-admin', ['email' => 'quan.tri@helidirect.com'])->assertFailed();
    }
}
