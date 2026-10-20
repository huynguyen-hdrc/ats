<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RolePerm;
use App\Support\Access;
use Illuminate\Database\Seeder;

/**
 * 4 vai trò hệ thống + ma trận quyền mặc định. Chạy lại được (không ghi đè quyền đã chỉnh).
 * Khi chuyển dữ liệu, role_perms thật từ Supabase sẽ thay các giá trị mặc định này.
 */
class RoleSeeder extends Seeder
{
    /** Quyền mặc định theo vai trò gốc; '*' = mọi hành động. */
    private const DEFAULTS = [
        'hr' => [
            'candidates' => '*', 'jobs' => '*', 'offers' => '*', 'employees' => '*', 'contracts' => '*',
            'leave' => '*', 'onboarding' => '*', 'probation' => '*', 'mail' => '*', 'reports' => '*',
            'backups' => ['view', 'create'], 'settings' => ['view', 'create', 'edit'],
        ],
        'manager' => [
            'candidates' => ['view', 'comment'], 'jobs' => ['view'], 'offers' => ['view'],
            'employees' => ['view'], 'leave' => ['view', 'create', 'approve'],
            'onboarding' => ['view', 'edit'], 'probation' => ['view', 'create', 'comment'],
            'mail' => ['view'], 'reports' => ['view'],
        ],
        'staff' => [
            'jobs' => ['view'], 'employees' => ['view'], 'leave' => ['view', 'create'], 'onboarding' => ['view'],
        ],
    ];

    public function run(): void
    {
        $sort = 10;
        foreach (Access::BASES as $key => $label) {
            Role::updateOrCreate(['key' => $key], ['label' => $label, 'base' => $key, 'sort' => $sort, 'is_system' => true]);
            $sort += 10;
        }

        foreach (self::DEFAULTS as $role => $modules) {
            if (RolePerm::where('role_key', $role)->exists()) {
                continue; // đã có cấu hình (do người dùng chỉnh hoặc đã chuyển dữ liệu)
            }
            foreach (Access::MODULES as $m => $_) {
                $allowedActions = $modules[$m] ?? [];
                foreach (Access::ACTIONS as $a => $__) {
                    RolePerm::create([
                        'role_key' => $role, 'module' => $m, 'action' => $a,
                        'allowed' => $allowedActions === '*' || in_array($a, (array) $allowedActions, true),
                    ]);
                }
            }
        }
    }
}
