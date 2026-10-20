<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Tạo (hoặc đặt lại mật khẩu cho) một quản trị viên. Mật khẩu ngẫu nhiên chỉ in ra một lần.
 * Lần đăng nhập đầu, hệ thống buộc bật xác thực 2 bước.
 */
class CreateAdmin extends Command
{
    protected $signature = 'hr:create-admin {email} {--name=} {--reset : Đặt lại mật khẩu nếu tài khoản đã tồn tại}';

    protected $description = 'Tạo tài khoản quản trị với mật khẩu ngẫu nhiên';

    public function handle(): int
    {
        $email = Str::lower(trim($this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Email không hợp lệ: {$email}");

            return self::FAILURE;
        }

        $this->callSilently('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);

        $user = User::where('email', $email)->first();
        if ($user && ! $this->option('reset')) {
            $this->error('Tài khoản đã tồn tại. Thêm --reset để đặt lại mật khẩu.');

            return self::FAILURE;
        }

        $password = self::randomPassword();
        $user ??= new User(['email' => $email]);
        $user->forceFill([
            'name' => $this->option('name') ?: ($user->name ?: Str::headline(Str::before($email, '@'))),
            'password' => $password,
            'role_key' => 'admin',
            'status' => 'active',
            'password_changed_at' => now(),
            'locked_at' => null,
        ])->save();
        $user->revokeSessions();
        Audit::log($user->wasRecentlyCreated ? 'user.admin_created' : 'user.admin_password_reset', $user, ['via' => 'cli']);

        $this->line("Email:    {$email}");
        $this->line("Mật khẩu: {$password}");
        $this->warn('Mật khẩu chỉ hiện một lần. Lần đăng nhập đầu sẽ yêu cầu bật xác thực 2 bước.');

        return self::SUCCESS;
    }

    /** 20 ký tự, đủ chữ hoa/thường/số/ký hiệu, bỏ các ký tự dễ nhầm (0 O l 1 I). */
    public static function randomPassword(int $length = 20): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '!@#$%*-_=+?'];
        $chars = array_map(fn ($s) => $s[random_int(0, strlen($s) - 1)], $sets);
        $all = implode('', $sets);
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
