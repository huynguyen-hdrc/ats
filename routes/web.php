<?php

use App\Http\Controllers\Api\InviteController;
use Illuminate\Support\Facades\Route;

// Nhận lời mời: khách (chưa đăng nhập) đặt mật khẩu lần đầu bằng token trong thư mời.
Route::post('/accept-invite', [InviteController::class, 'accept'])
    ->middleware(['guest', 'throttle:10,1'])
    ->name('invite.accept');

// Mọi đường dẫn còn lại (trừ /api, /legacy, /build) trả về giao diện SPA; router phía trình duyệt lo phần còn lại.
Route::view('/login', 'app')->name('login');
Route::view('/{path?}', 'app')
    ->where('path', '^(?!api/|api$|user/|legacy/|build/|up$).*')
    ->name('spa');
