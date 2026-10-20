<?php

/*
| Route API. Nhóm ngoài (bootstrap/app.php) đã gắn: web (phiên + CSRF), auth, active, admin2fa.
| Mỗi route khai báo quyền module × hành động bằng middleware perm:module,action.
*/

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\CandidateController;
use App\Http\Controllers\Api\CandidateCvController;
use App\Http\Controllers\Api\ExcelController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('me', [MeController::class, 'show'])->name('me');

// ---- Hệ thống: user, vai trò, nhật ký (nhóm quyền "settings") ----
Route::get('users', [UserController::class, 'index'])->middleware('perm:settings,view');
Route::post('users', [UserController::class, 'store'])->middleware('perm:settings,create');
Route::patch('users/{user}', [UserController::class, 'update'])->middleware('perm:settings,edit');
Route::post('users/{user}/lock', [UserController::class, 'lock'])->middleware('perm:settings,edit');
Route::post('users/{user}/unlock', [UserController::class, 'unlock'])->middleware('perm:settings,edit');
Route::post('users/{user}/resend-invite', [UserController::class, 'resendInvite'])->middleware('perm:settings,create');
Route::post('users/{user}/reset-two-factor', [UserController::class, 'resetTwoFactor'])->middleware('perm:settings,edit');

Route::get('roles', [RoleController::class, 'index'])->middleware('perm:settings,view');
Route::post('roles', [RoleController::class, 'store'])->middleware('perm:settings,create');
Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('perm:settings,edit');
Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('perm:settings,delete');
Route::put('roles/{role}/perms', [RoleController::class, 'updatePerms'])->middleware('perm:settings,edit');

Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('perm:settings,view');

// ---- Excel dùng chung (quyền kiểm theo module của từng loại dữ liệu) ----
Route::get('excel/{entity}/template', [ExcelController::class, 'template']);
Route::get('excel/{entity}/export', [ExcelController::class, 'export']);
Route::post('excel/{entity}/preview', [ExcelController::class, 'preview']);
Route::post('excel/{entity}/import', [ExcelController::class, 'import']);

// ---- Vị trí & JD ----
Route::get('jobs', [JobController::class, 'index'])->middleware('perm:jobs,view');
Route::get('jobs/{job}', [JobController::class, 'show'])->middleware('perm:jobs,view');
Route::post('jobs', [JobController::class, 'store'])->middleware('perm:jobs,create');
Route::patch('jobs/{job}', [JobController::class, 'update'])->middleware('perm:jobs,edit');
Route::delete('jobs/{job}', [JobController::class, 'destroy'])->middleware('perm:jobs,delete');

// ---- Tuyển dụng: vòng, ứng viên, lịch sử, CV ----
Route::get('stages', [CandidateController::class, 'stages'])->middleware('perm:candidates,view');
Route::post('candidates/parse-cv', [CandidateCvController::class, 'parse'])->middleware(['perm:candidates,create', 'throttle:30,1']);
Route::get('candidates', [CandidateController::class, 'index'])->middleware('perm:candidates,view');
Route::get('candidates/{candidate}', [CandidateController::class, 'show'])->middleware('perm:candidates,view');
Route::post('candidates', [CandidateController::class, 'store'])->middleware('perm:candidates,create');
Route::patch('candidates/{candidate}', [CandidateController::class, 'update'])->middleware('perm:candidates,edit');
Route::patch('candidates/{candidate}/stage', [CandidateController::class, 'stage'])->middleware('perm:candidates,edit');
Route::patch('candidates/{candidate}/status', [CandidateController::class, 'status'])->middleware('perm:candidates,edit');
Route::post('candidates/{candidate}/notes', [CandidateController::class, 'note'])->middleware('perm:candidates,comment');
Route::delete('candidates/{candidate}', [CandidateController::class, 'destroy'])->middleware('perm:candidates,delete');
Route::post('candidates/{candidate}/cv', [CandidateCvController::class, 'store'])->middleware('perm:candidates,edit|create');
Route::get('candidates/{candidate}/cv', [CandidateCvController::class, 'show'])->middleware('perm:candidates,view');
Route::delete('candidates/{candidate}/cv', [CandidateCvController::class, 'destroy'])->middleware('perm:candidates,edit');
