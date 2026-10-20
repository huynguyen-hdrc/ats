<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CandidateRequest;
use App\Models\Candidate;
use App\Models\CandidateEvent;
use App\Models\Stage;
use App\Services\CvFiles;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Ứng viên — chuyển từ viewPipeline/openAddCandidate/openCandidate của app cũ (index.html:478-631). */
class CandidateController extends Controller
{
    public function stages(): JsonResponse
    {
        return response()->json(['data' => Stage::ordered()]);
    }

    /** Toàn bộ ứng viên (cả đã loại/rút — giao diện tự lọc), mới đổi vòng lên trước như app cũ. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['job_id' => ['nullable', 'uuid']]);
        $rows = Candidate::with('job:id,title,department')
            ->when($request->query('job_id'), fn ($q, $id) => $q->where('job_id', $id))
            ->orderByDesc('stage_changed_at')->orderBy('full_name')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function show(Candidate $candidate): JsonResponse
    {
        return response()->json(['data' => $candidate->load('job:id,title,department'), 'events' => $this->events($candidate)]);
    }

    public function store(CandidateRequest $request): JsonResponse
    {
        $c = Candidate::create($request->validated());
        Audit::log('candidates.create', $c, ['full_name' => $c->full_name]);

        return response()->json(['data' => $c->fresh()->load('job:id,title,department')], 201);
    }

    public function update(CandidateRequest $request, Candidate $candidate): JsonResponse
    {
        $from = $candidate->only(['stage', 'status']);
        $candidate->update($request->validated());
        $this->auditChanges($candidate, $from);

        return response()->json(['data' => $candidate->fresh()->load('job:id,title,department')]);
    }

    /** Kéo thả trên kanban. App cũ không chặn ở giao diện; ở đây bắt buộc quyền candidates.edit (route). */
    public function stage(Request $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validate(['stage' => ['required', 'string', Rule::exists('stages', 'key')]],
            [], ['stage' => 'vòng']);
        $from = $candidate->only(['stage', 'status']);
        $candidate->update(['stage' => $data['stage']]);
        $this->auditChanges($candidate, $from);

        return response()->json(['data' => $candidate->fresh()->load('job:id,title,department')]);
    }

    /** "Loại ứng viên" / "Mở lại". */
    public function status(Request $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(Candidate::STATUSES)]], [], ['status' => 'trạng thái']);
        $from = $candidate->only(['stage', 'status']);
        $candidate->update(['status' => $data['status']]);
        $this->auditChanges($candidate, $from);

        return response()->json(['data' => $candidate->fresh()->load('job:id,title,department')]);
    }

    public function note(Request $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']], [], ['body' => 'ghi chú']);
        $body = trim($data['body']);
        abort_if($body === '', 422, 'Ghi chú đang trống.');
        CandidateEvent::create(['candidate_id' => $candidate->id, 'type' => 'note', 'body' => $body]);

        return response()->json(['events' => $this->events($candidate)], 201);
    }

    public function destroy(Candidate $candidate, CvFiles $files): JsonResponse
    {
        $candidate->delete(); // candidate_events xoá theo (cascade)
        $files->purge($candidate->id);
        Audit::log('candidates.delete', $candidate, ['full_name' => $candidate->full_name, 'email' => $candidate->email]);

        return response()->json(null, 204);
    }

    private function auditChanges(Candidate $c, array $from): void
    {
        if ($c->wasChanged('stage')) {
            Audit::log('candidates.stage', $c, ['from' => $from['stage'], 'to' => $c->stage]);
        }
        if ($c->wasChanged('status')) {
            Audit::log('candidates.status', $c, ['from' => $from['status'], 'to' => $c->status]);
        }
    }

    private function events(Candidate $c)
    {
        return $c->events()->with('author:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn (CandidateEvent $e) => [
                'id' => $e->id, 'type' => $e->type, 'body' => $e->body,
                'from_stage' => $e->from_stage, 'to_stage' => $e->to_stage,
                'created_at' => $e->created_at, 'author' => $e->author?->name,
            ]);
    }
}
