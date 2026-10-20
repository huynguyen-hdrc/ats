<?php

namespace App\Observers;

use App\Models\Candidate;
use App\Models\CandidateEvent;
use Illuminate\Support\Facades\Auth;

/**
 * Thay các trigger của Supabase trên bảng candidates (selftest nhóm B):
 * - thêm mới → sự kiện "created";
 * - đổi vòng → sự kiện stage_change (từ → đến) và cập nhật stage_changed_at;
 * - đổi trạng thái → sự kiện status_change (body = mã trạng thái mới).
 * Sửa thông tin khác không chạm stage_changed_at. Chạy cho mọi đường ghi (API, nhập Excel).
 */
class CandidateObserver
{
    public function creating(Candidate $c): void
    {
        $c->stage_changed_at ??= now();
        $c->created_by ??= Auth::id();
    }

    public function created(Candidate $c): void
    {
        CandidateEvent::create(['candidate_id' => $c->id, 'type' => 'created']);
    }

    public function updating(Candidate $c): void
    {
        if ($c->isDirty('stage') && ! $c->isDirty('stage_changed_at')) {
            $c->stage_changed_at = now();
        }
    }

    public function updated(Candidate $c): void
    {
        if ($c->wasChanged('stage')) {
            CandidateEvent::create([
                'candidate_id' => $c->id, 'type' => 'stage_change',
                'from_stage' => $c->getOriginal('stage'), 'to_stage' => $c->stage,
            ]);
        }
        if ($c->wasChanged('status')) {
            CandidateEvent::create(['candidate_id' => $c->id, 'type' => 'status_change', 'body' => $c->status]);
        }
    }
}
