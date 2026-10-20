<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * "Quên mật khẩu" luôn trả cùng một phản hồi, dù email có tồn tại, bị chặn tần suất hay không,
 * để không ai dò được email nào có tài khoản.
 */
class PasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function toResponse($request)
    {
        return response()->json(['message' => __('passwords.sent')]);
    }
}
