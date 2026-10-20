<?php

namespace App\Providers;

use Anthropic\Client;
use App\Models\User;
use GuzzleHttp\Client as Guzzle;
use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Claude API (đọc CV). Timeout do transporter quyết định, SDK không tự cắt.
        $this->app->bind(Client::class, fn () => new Client(
            apiKey: (string) config('services.anthropic.key'),
            baseUrl: config('services.anthropic.base_url'),
            requestOptions: [
                'maxRetries' => 1,
                'transporter' => new Guzzle(['timeout' => 25, 'connect_timeout' => 5]),
            ],
        ));
    }

    public function boot(): void
    {
        // Chặn lazy-loading và gán thuộc tính lạ khi dev/test. Không chặn đọc thuộc tính chưa nạp:
        // model vừa create() chưa có các cột mặc định của DB, đọc chúng là bình thường.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Mật khẩu: tối thiểu 10 ký tự, không nằm trong danh sách đã bị lộ (HIBP, k-anonymity).
        // Khi test không gọi mạng.
        Password::defaults(fn () => $this->app->runningUnitTests()
            ? Password::min(10)
            : Password::min(10)->uncompromised());

        Event::listen(function (Login $e) {
            if ($e->user instanceof User) {
                $e->user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->saveQuietly();
                Audit::log('auth.login', $e->user, ['remember' => $e->remember], $e->user->id);
            }
        });
        Event::listen(function (Failed $e) {
            Audit::log('auth.login_failed', null, ['email' => $e->credentials['email'] ?? null], $e->user?->getAuthIdentifier());
        });
        Event::listen(function (Lockout $e) {
            Audit::log('auth.lockout', null, ['email' => $e->request->input('email')]);
        });
        Event::listen(function (Logout $e) {
            if ($e->user) {
                Audit::log('auth.logout', null, [], $e->user->getAuthIdentifier());
            }
        });
    }
}
