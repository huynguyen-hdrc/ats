<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /** Thông tin cho giao diện: user, vai trò, ma trận quyền đầy đủ, yêu cầu 2FA. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('role');
        $perms = [];
        foreach (array_keys(Access::MODULES) as $m) {
            foreach (array_keys(Access::ACTIONS) as $a) {
                $perms[$m][$a] = $user->hasPerm($m, $a);
            }
        }

        return response()->json([
            'user' => new UserResource($user),
            'role' => ['key' => $user->role_key, 'label' => $user->role?->label, 'base' => $user->base()],
            'perms' => $perms,
            'two_factor_required' => $user->isAdmin() && ! $user->hasTwoFactor(),
            'catalog' => ['modules' => Access::MODULES, 'actions' => Access::ACTIONS, 'bases' => Access::BASES],
        ]);
    }
}
