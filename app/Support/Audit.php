<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Ghi nhật ký thao tác. Không bao giờ làm hỏng thao tác chính: lỗi ghi log chỉ được report.
 */
final class Audit
{
    public static function log(string $action, Model|string|null $subject = null, array $meta = [], ?string $userId = null): void
    {
        try {
            $req = request();
            [$type, $id] = match (true) {
                $subject instanceof Model => [Str::snake(class_basename($subject)), (string) $subject->getKey()],
                is_string($subject) => [$subject, null],
                default => [null, null],
            };
            AuditLog::create([
                'user_id' => $userId ?? Auth::id(),
                'action' => $action,
                'subject_type' => $type,
                'subject_id' => $id,
                'meta' => $meta ?: null,
                'ip' => $req?->ip(),
                'user_agent' => Str::limit((string) $req?->userAgent(), 250, ''),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
