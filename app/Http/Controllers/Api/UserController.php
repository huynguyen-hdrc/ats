<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::with('role')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->orderBy('name')
            ->get();

        return UserResource::collection($users);
    }

    /** Mời user mới: tạo tài khoản trạng thái "đã mời" và gửi thư đặt mật khẩu. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            'role_key' => ['required', 'string', Rule::exists('roles', 'key')],
            'employee_id' => ['nullable', 'uuid'],
        ], ['email.unique' => 'Email này đã có tài khoản.']);

        $this->assertCanAssignRole($request->user(), $data['role_key']);

        $user = User::create([
            ...$data,
            'email' => mb_strtolower(trim($data['email'])),
            'status' => 'invited',
            'invited_at' => now(),
            'invited_by' => $request->user()->id,
        ]);
        Audit::log('user.invited', $user, ['role' => $user->role_key]);

        $sent = $this->trySendInvite($user);

        return (new UserResource($user->load('role')))
            ->additional(['invite_sent' => $sent])
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserResource
    {
        $actor = $request->user();
        $this->assertCanManage($actor, $user);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'role_key' => ['sometimes', 'required', 'string', Rule::exists('roles', 'key')],
            'employee_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        if (isset($data['role_key']) && $data['role_key'] !== $user->role_key) {
            $this->assertCanAssignRole($actor, $data['role_key']);
            abort_if($user->is($actor), 422, 'Không tự đổi vai trò của chính mình. Nhờ một quản trị viên khác.');
            if ($user->isAdmin() && Role::find($data['role_key'])?->base !== 'admin') {
                $this->assertNotLastAdmin($user);
            }
            Audit::log('user.role_changed', $user, ['from' => $user->role_key, 'to' => $data['role_key']]);
        }

        $user->fill($data)->save();
        $user->forgetPermCache();

        return new UserResource($user->load('role'));
    }

    public function lock(Request $request, User $user): UserResource
    {
        $actor = $request->user();
        abort_if($user->is($actor), 422, 'Không tự khoá tài khoản của chính mình.');
        $this->assertCanManage($actor, $user);
        if ($user->isAdmin()) {
            $this->assertNotLastAdmin($user);
        }

        $user->forceFill(['status' => 'locked', 'locked_at' => now()])->save();
        $user->revokeSessions();
        Audit::log('user.locked', $user);

        return new UserResource($user->load('role'));
    }

    public function unlock(Request $request, User $user): UserResource
    {
        $this->assertCanManage($request->user(), $user);
        abort_unless($user->status === 'locked', 422, 'Tài khoản này không bị khoá.');

        $user->forceFill(['status' => $user->password ? 'active' : 'invited', 'locked_at' => null])->save();
        Audit::log('user.unlocked', $user);

        return new UserResource($user->load('role'));
    }

    public function resendInvite(Request $request, User $user): JsonResponse
    {
        $this->assertCanManage($request->user(), $user);
        abort_unless($user->status === 'invited', 422, 'Chỉ gửi lại lời mời cho tài khoản chưa kích hoạt.');

        $user->forceFill(['invited_at' => now()])->save();
        Audit::log('user.invite_resent', $user);
        $sent = $this->trySendInvite($user);

        return response()->json([
            'invite_sent' => $sent,
            'message' => $sent ? 'Đã gửi lại lời mời.' : 'Không gửi được email. Kiểm tra cấu hình SMTP.',
        ], $sent ? 200 : 502);
    }

    /** Xoá 2FA của một user (mất điện thoại). Chỉ admin. */
    public function resetTwoFactor(Request $request, User $user): UserResource
    {
        abort_unless($request->user()->isAdmin(), 403, 'Chỉ quản trị viên được đặt lại xác thực 2 bước.');
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $user->revokeSessions();
        Audit::log('user.two_factor_reset', $user);

        return new UserResource($user->load('role'));
    }

    private function trySendInvite(User $user): bool
    {
        try {
            $user->sendInvite();

            return true;
        } catch (\Throwable $e) {
            report($e);
            Audit::log('user.invite_failed', $user, ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return false;
        }
    }

    /** Chỉ admin được đụng tới tài khoản admin. */
    private function assertCanManage(User $actor, User $target): void
    {
        abort_if($target->isAdmin() && ! $actor->isAdmin(), 403, 'Chỉ quản trị viên được thay đổi tài khoản quản trị.');
    }

    /** Chỉ admin được gán vai trò có gốc admin. */
    private function assertCanAssignRole(User $actor, string $roleKey): void
    {
        $base = Role::find($roleKey)?->base;
        abort_if($base === 'admin' && ! $actor->isAdmin(), 403, 'Chỉ quản trị viên được gán vai trò quản trị.');
    }

    private function assertNotLastAdmin(User $user): void
    {
        $others = User::where('id', '!=', $user->id)->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->where('base', 'admin'))->count();
        abort_if($others === 0, 422, 'Đây là quản trị viên đang hoạt động cuối cùng.');
    }
}
