<?php

namespace App\Models;

use App\Notifications\InviteUser;
use App\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    use HasFactory, HasUuids, Notifiable, TwoFactorAuthenticatable;

    protected $fillable = ['name', 'email', 'password', 'role_key', 'status', 'employee_id', 'invited_at', 'invited_by'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    /** Ma trận quyền nạp một lần mỗi request. */
    private ?array $permCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'invited_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_key', 'key');
    }

    /** Vai trò gốc (admin/hr/manager/staff) mà vai trò của user kế thừa. */
    public function base(): string
    {
        return $this->role?->base ?? 'staff';
    }

    public function isAdmin(): bool
    {
        return $this->base() === 'admin';
    }

    /** HR theo nghĩa app cũ: vai trò gốc admin hoặc hr. */
    public function isHr(): bool
    {
        return in_array($this->base(), ['admin', 'hr'], true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function hasPerm(string $module, string $action): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->permMatrix()[$module][$action] ?? false;
    }

    /** @return array<string, array<string, bool>> */
    public function permMatrix(): array
    {
        if ($this->permCache === null) {
            $this->permCache = [];
            RolePerm::where('role_key', $this->role_key)->where('allowed', true)
                ->get(['module', 'action'])
                ->each(function ($p) {
                    $this->permCache[$p->module][$p->action] = true;
                });
        }

        return $this->permCache;
    }

    public function forgetPermCache(): void
    {
        $this->permCache = null;
    }

    /** Huỷ mọi phiên đăng nhập (trừ phiên $keepSessionId) và vô hiệu token "ghi nhớ". */
    public function revokeSessions(?string $keepSessionId = null): void
    {
        DB::table('sessions')->where('user_id', $this->id)
            ->when($keepSessionId, fn ($q) => $q->where('id', '!=', $keepSessionId))
            ->delete();
        $this->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }

    public function sendInvite(): void
    {
        $token = Password::broker('invites')->createToken($this);
        $this->notify(new InviteUser($token));
    }
}
