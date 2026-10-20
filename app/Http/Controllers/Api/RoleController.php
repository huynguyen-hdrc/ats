<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\RolePerm;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::withCount('users')->orderBy('sort')->orderBy('label')->get();
        $perms = RolePerm::where('allowed', true)->get(['role_key', 'module', 'action']);

        return response()->json([
            'data' => $roles->map(fn (Role $r) => $this->present($r, $perms->where('role_key', $r->key))),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'key')],
            'label' => ['required', 'string', 'max:120'],
            'base' => ['required', Rule::in(array_keys(Access::BASES))],
            'sort' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ], ['key.regex' => 'Mã vai trò chỉ gồm chữ thường, số và dấu gạch dưới, bắt đầu bằng chữ.']);
        abort_if($data['base'] === 'admin' && ! $request->user()->isAdmin(), 403, 'Chỉ quản trị viên được tạo vai trò quản trị.');

        $role = Role::create($data + ['sort' => 100, 'is_system' => false]);
        Audit::log('role.created', $role, $data);

        return response()->json(['data' => $this->present($role, collect())], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:120'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'base' => ['sometimes', Rule::in(array_keys(Access::BASES))],
        ]);
        $actor = $request->user();
        if (isset($data['base']) && $data['base'] !== $role->base) {
            abort_if($role->is_system, 422, 'Không đổi vai trò gốc của vai trò hệ thống.');
            abort_if(! $actor->isAdmin() && in_array('admin', [$data['base'], $role->base], true), 403,
                'Chỉ quản trị viên được đổi vai trò liên quan tới quyền quản trị.');
        }
        $role->update($data);
        Audit::log('role.updated', $role, $data);

        return response()->json(['data' => $this->present($role, RolePerm::where('role_key', $role->key)->where('allowed', true)->get())]);
    }

    public function destroy(Role $role): JsonResponse
    {
        abort_if($role->is_system, 422, 'Không xoá vai trò hệ thống.');
        abort_if($role->users()->exists(), 422, 'Vai trò đang có người dùng. Chuyển họ sang vai trò khác trước.');
        $role->delete();
        Audit::log('role.deleted', 'role', ['key' => $role->key]);

        return response()->json(null, 204);
    }

    /** Ghi cả ma trận quyền của một vai trò: { perms: { module: { action: bool } } }. */
    public function updatePerms(Request $request, Role $role): JsonResponse
    {
        $actor = $request->user();
        abort_if($role->base === 'admin', 422, 'Vai trò quản trị luôn có đủ quyền.');
        abort_if(! $actor->isAdmin() && $role->key === $actor->role_key, 403,
            'Không tự sửa quyền của vai trò mình đang giữ. Nhờ quản trị viên.');

        $data = $request->validate(['perms' => ['required', 'array']]);
        $before = RolePerm::where('role_key', $role->key)->where('allowed', true)->get()
            ->map(fn ($p) => "{$p->module}.{$p->action}")->all();

        $after = [];
        DB::transaction(function () use ($data, $role, &$after) {
            foreach (Access::MODULES as $m => $_) {
                foreach (Access::ACTIONS as $a => $__) {
                    $allowed = (bool) ($data['perms'][$m][$a] ?? false);
                    RolePerm::updateOrCreate(
                        ['role_key' => $role->key, 'module' => $m, 'action' => $a],
                        ['allowed' => $allowed]
                    );
                    if ($allowed) {
                        $after[] = "{$m}.{$a}";
                    }
                }
            }
        });

        Audit::log('role.perms_updated', $role, [
            'granted' => array_values(array_diff($after, $before)),
            'revoked' => array_values(array_diff($before, $after)),
        ]);

        return response()->json(['data' => $this->present($role, RolePerm::where('role_key', $role->key)->where('allowed', true)->get())]);
    }

    private function present(Role $role, $allowed): array
    {
        $matrix = [];
        foreach (Access::MODULES as $m => $_) {
            foreach (Access::ACTIONS as $a => $__) {
                $matrix[$m][$a] = $role->base === 'admin'
                    || $allowed->contains(fn ($p) => $p->module === $m && $p->action === $a);
            }
        }

        return [
            'key' => $role->key,
            'label' => $role->label,
            'base' => $role->base,
            'sort' => $role->sort,
            'is_system' => $role->is_system,
            'users_count' => $role->users_count ?? $role->users()->count(),
            'perms' => $matrix,
        ];
    }
}
