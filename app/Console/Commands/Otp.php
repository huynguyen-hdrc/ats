<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PragmaRX\Google2FA\Google2FA;

/** In mã 2FA hiện tại của admin demo (local) hoặc của một secret bất kỳ. */
class Otp extends Command
{
    protected $signature = 'hr:otp {secret? : Secret TOTP; bỏ trống = admin demo}';

    protected $description = 'In mã 2FA 6 số hiện tại (chỉ local)';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Chỉ chạy ở môi trường local.');

            return self::FAILURE;
        }
        $g = new Google2FA;
        $secret = $this->argument('secret') ?: DemoUsers::ADMIN_SECRET;
        $this->line($g->getCurrentOtp($secret).'  (còn '.(30 - time() % 30).' giây)');

        return self::SUCCESS;
    }
}
