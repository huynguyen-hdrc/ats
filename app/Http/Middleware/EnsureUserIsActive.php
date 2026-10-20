<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Tài khoản bị khoá giữa chừng: cắt phiên ngay ở request kế tiếp. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Tài khoản không còn hoạt động. Liên hệ phòng Nhân sự.',
                'code' => 'account_inactive',
            ], 401);
        }

        return $next($request);
    }
}
