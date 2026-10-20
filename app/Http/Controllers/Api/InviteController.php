<?php

namespace App\Http\Controllers\Api;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class InviteController extends Controller
{
    use PasswordValidationRules;

    /** Đặt mật khẩu lần đầu từ thư mời; kích hoạt tài khoản. Không tự đăng nhập. */
    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => $this->passwordRules(),
        ]);

        $status = Password::broker('invites')->reset(
            ['email' => mb_strtolower($data['email'])] + $data,
            function (User $user, string $password) {
                if ($user->status === 'locked') {
                    return; // tài khoản đã bị khoá sau khi mời: không kích hoạt
                }
                $user->forceFill([
                    'password' => Hash::make($password),
                    'password_changed_at' => now(),
                    'status' => 'active',
                ])->save();
                Audit::log('auth.invite_accepted', $user, [], $user->id);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'Link mời không hợp lệ hoặc đã hết hạn. Nhờ phòng Nhân sự gửi lại lời mời.',
            ]);
        }

        $user = User::where('email', mb_strtolower($data['email']))->first();
        if (! $user?->isActive()) {
            throw ValidationException::withMessages(['email' => 'Tài khoản này đang bị khoá. Liên hệ phòng Nhân sự.']);
        }

        return response()->json(['message' => 'Đã đặt mật khẩu. Bạn có thể đăng nhập.']);
    }
}
