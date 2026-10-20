<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin chưa bật 2FA chỉ được gọi các route trong danh sách cho phép (xem thông tin bản thân,
 * đăng xuất). Các route bật 2FA của Fortify nằm ngoài /api nên không bị chặn.
 */
class RequireTwoFactorForAdmins
{
    private const ALLOWED = ['api/me'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->isAdmin() && ! $user->hasTwoFactor() && ! $request->is(...self::ALLOWED)) {
            return response()->json([
                'message' => 'Tài khoản quản trị phải bật xác thực 2 bước trước khi dùng hệ thống.',
                'code' => 'two_factor_required',
            ], 403);
        }

        return $next($request);
    }
}
