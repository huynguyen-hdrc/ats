<?php

namespace App\Support;

/**
 * Danh mục quyền, giữ đúng như app cũ (PERM_MODULES / PERM_ACTIONS / ROLE_BASES).
 */
final class Access
{
    public const MODULES = [
        'candidates' => 'Ứng viên',
        'jobs' => 'Vị trí & JD',
        'offers' => 'Offer letter',
        'employees' => 'Hồ sơ nhân sự',
        'contracts' => 'Hợp đồng',
        'leave' => 'Nghỉ phép',
        'onboarding' => 'Onboarding',
        'probation' => 'Thử việc',
        'mail' => 'Thư & Email',
        'reports' => 'Báo cáo',
        'backups' => 'Sao lưu',
        'settings' => 'Phân quyền',
    ];

    public const ACTIONS = [
        'view' => 'Xem',
        'create' => 'Tạo mới',
        'edit' => 'Sửa',
        'delete' => 'Xoá',
        'approve' => 'Duyệt / xác nhận',
        'comment' => 'Ghi chú / bình luận',
    ];

    public const BASES = [
        'admin' => 'Quản trị',
        'hr' => 'Nhân sự',
        'manager' => 'Quản lý',
        'staff' => 'Nhân viên',
    ];

    public const USER_STATUSES = ['invited', 'active', 'locked'];

    public static function isModule(string $m): bool
    {
        return array_key_exists($m, self::MODULES);
    }

    public static function isAction(string $a): bool
    {
        return array_key_exists($a, self::ACTIONS);
    }
}
