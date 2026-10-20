<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequirePerm;
use App\Http\Middleware\RequireTwoFactorForAdmins;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // API dùng phiên cookie của nhóm `web` (cùng domain với giao diện), không dùng token.
            Route::middleware(['web', 'auth', 'active', 'admin2fa'])
                ->prefix('api')
                ->name('api.')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // hr.helidirect.io chạy sau CDN của Hostinger: lấy IP thật và https từ header của proxy.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin2fa' => RequireTwoFactorForAdmins::class,
            'perm' => RequirePerm::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
