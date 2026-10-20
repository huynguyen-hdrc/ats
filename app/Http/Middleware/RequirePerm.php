<?php

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dùng: ->middleware('perm:candidates,edit')
 * Nhiều hành động cách nhau bằng "|" = cần ít nhất một: ->middleware('perm:candidates,edit|create')
 */
class RequirePerm
{
    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        $actions = explode('|', $action);
        if (! Access::isModule($module) || array_filter($actions, fn ($a) => ! Access::isAction($a))) {
            throw new \LogicException("Quyền không hợp lệ: {$module}.{$action}");
        }

        $user = $request->user();
        foreach ($actions as $a) {
            if ($user?->hasPerm($module, $a)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => sprintf('Vai trò của bạn không có quyền %s ở nhóm "%s".',
                implode(' hoặc ', array_map(fn ($a) => mb_strtolower(Access::ACTIONS[$a]), $actions)),
                Access::MODULES[$module]),
            'code' => 'forbidden',
        ], 403);
    }
}
