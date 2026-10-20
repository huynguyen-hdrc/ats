<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /** Sai quá số lần này trong cửa sổ LOCK_SECONDS thì tạm chặn đăng nhập. */
    public const MAX_FAILS = 5;

    public const LOCK_SECONDS = 15 * 60;

    public function register(): void
    {
        // bind() chứ không instance(): Fortify gọi app(Contract, ['status' => …]) có tham số,
        // container sẽ bỏ qua instance đã đăng ký.
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::authenticateUsing(function (Request $request) {
            $key = self::failKey($request);

            if (RateLimiter::tooManyAttempts($key, self::MAX_FAILS)) {
                event(new Lockout($request));
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
                throw ValidationException::withMessages([
                    'email' => "Sai mật khẩu quá nhiều lần. Thử lại sau {$minutes} phút.",
                ]);
            }

            $user = User::where('email', Str::lower(trim((string) $request->input('email'))))->first();

            // Chỉ tài khoản đang hoạt động mới đăng nhập được; thông báo lỗi giống nhau
            // để không lộ email nào tồn tại hay đang bị khoá.
            if ($user && $user->isActive() && $user->password
                && Hash::check((string) $request->input('password'), $user->password)) {
                RateLimiter::clear($key);
                // Vừa gõ đúng mật khẩu: tính là đã xác nhận, khỏi hỏi lại ngay khi bật 2FA.
                $request->session()->put('auth.password_confirmed_at', time());

                return $user;
            }

            RateLimiter::hit($key, self::LOCK_SECONDS);

            return null;
        });

        // Chặn dội request vào route đăng nhập (đếm cả lần đúng), rộng hơn giới hạn sai mật khẩu.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));
    }

    public static function failKey(Request $request): string
    {
        return 'login-fail:'.Str::transliterate(Str::lower((string) $request->input('email'))).'|'.$request->ip();
    }
}
