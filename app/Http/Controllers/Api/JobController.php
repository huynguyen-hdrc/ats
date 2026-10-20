<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\JobRequest;
use App\Models\JobOpening;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->withCounts($request, JobOpening::query())->orderBy('title')->get()]);
    }

    public function show(Request $request, JobOpening $job): JsonResponse
    {
        return response()->json(['data' => $this->withCounts($request, JobOpening::whereKey($job->id))->first()]);
    }

    /**
     * Số ứng viên trên thẻ vị trí (app cũ): "{đã tuyển}/{cần}" = ứng viên ở vòng hired (mọi trạng thái),
     * "{n} ứng viên" = ứng viên đang active. Chỉ trả khi user được xem ứng viên.
     */
    private function withCounts(Request $request, Builder $q): Builder
    {
        if (! $request->user()->hasPerm('candidates', 'view')) {
            return $q;
        }

        return $q->withCount([
            'candidates as candidates_count' => fn ($c) => $c->where('status', 'active'),
            'candidates as hired_count' => fn ($c) => $c->where('stage', 'hired'),
        ]);
    }

    public function store(JobRequest $request): JsonResponse
    {
        $job = JobOpening::create($request->validated() + ['created_by' => $request->user()->id]);
        Audit::log('jobs.create', $job, ['title' => $job->title]);

        return response()->json(['data' => $job->fresh()], 201);
    }

    public function update(JobRequest $request, JobOpening $job): JsonResponse
    {
        $job->update($request->validated());

        return response()->json(['data' => $job->fresh()]);
    }

    public function destroy(JobOpening $job): JsonResponse
    {
        // Ứng viên của vị trí này giữ nguyên, chỉ mất liên kết (job_id → null), như app cũ.
        $job->delete();
        Audit::log('jobs.delete', $job, ['title' => $job->title]);

        return response()->json(null, 204);
    }
}
