<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $page = AuditLog::with('user:id,name,email')
            // "auth." = cả nhóm (tiền tố); "auth.login" = đúng một thao tác.
            ->when($request->query('action'), fn ($q, $a) => str_ends_with($a, '.')
                ? $q->where('action', 'like', $a.'%')
                : $q->where('action', $a))
            ->when($request->query('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->query('from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->where('created_at', '<', date('Y-m-d', strtotime($d.' +1 day'))))
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json($page->through(fn (AuditLog $l) => [
            'id' => $l->id,
            'at' => $l->created_at?->toIso8601String(),
            'action' => $l->action,
            'user' => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name, 'email' => $l->user->email] : null,
            'subject_type' => $l->subject_type,
            'subject_id' => $l->subject_id,
            'meta' => $l->meta,
            'ip' => $l->ip,
        ]));
    }
}
