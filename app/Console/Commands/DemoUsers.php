<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use PragmaRX\Google2FA\Google2FA;

/**
 * Tài khoản demo cho máy local (test giao diện / Postman). Chỉ chạy khi APP_ENV=local.
 * Admin demo có sẵn 2FA với secret cố định để sinh mã bằng app Authenticator hoặc lệnh hr:otp.
 */
class DemoUsers extends Command
{
    public const PASSWORD = 'Demo-Pass-2026';

    /** Secret TOTP cố định của admin demo (chỉ dùng ở local). */
    public const ADMIN_SECRET = 'HRDEMOADMINLOCAL';

    protected $signature = 'hr:demo-users';

    protected $description = 'Tạo tài khoản demo cho 4 vai trò (chỉ môi trường local)';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Chỉ chạy ở môi trường local (APP_ENV=local).');

            return self::FAILURE;
        }
        $this->callSilently('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $rows = [];
        foreach (['admin' => 'Quản trị', 'hr' => 'Nhân sự', 'manager' => 'Quản lý', 'staff' => 'Nhân viên'] as $role => $label) {
            $u = User::updateOrCreate(['email' => "{$role}.demo@helidirect.com"], [
                'name' => "Demo {$label}", 'role_key' => $role, 'status' => 'active',
            ]);
            $u->forceFill(['password' => self::PASSWORD, 'password_changed_at' => now(), 'locked_at' => null]);
            if ($role === 'admin') {
                $u->forceFill([
                    'two_factor_secret' => encrypt(self::ADMIN_SECRET),
                    'two_factor_recovery_codes' => encrypt(json_encode(['demo-recovery-0001', 'demo-recovery-0002'])),
                    'two_factor_confirmed_at' => now(),
                ]);
            }
            $u->save();
            $rows[] = [$u->email, $label, $role === 'admin' ? 'bật (php artisan hr:otp)' : '—'];
        }

        $this->table(['Email', 'Vai trò', '2FA'], $rows);
        $this->line('Mật khẩu chung: '.self::PASSWORD);
        $this->line('Mã 2FA hiện tại của admin demo: '.(new Google2FA)->getCurrentOtp(self::ADMIN_SECRET));

        return self::SUCCESS;
    }
}
