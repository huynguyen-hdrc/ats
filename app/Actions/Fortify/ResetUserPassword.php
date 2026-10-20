<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // Người được mời mà dùng "quên mật khẩu" cũng đã chứng minh sở hữu email: kích hoạt luôn.
        // Tài khoản bị khoá vẫn giữ nguyên trạng thái khoá.
        $user->forceFill([
            'password' => Hash::make($input['password']),
            'password_changed_at' => now(),
            'status' => $user->status === 'invited' ? 'active' : $user->status,
        ])->save();
        $user->revokeSessions();
        Audit::log('auth.password_reset', $user, [], $user->id);
    }
}
