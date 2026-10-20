<?php

namespace App\Models;

use App\Observers\CandidateObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ứng viên. Sự kiện lịch sử và stage_changed_at do CandidateObserver ghi (thay trigger của Supabase). */
#[ObservedBy(CandidateObserver::class)]
class Candidate extends Model
{
    use HasUuids;

    public const STATUSES = ['active', 'rejected', 'withdrawn'];

    /** Độ dài tối đa của nội dung CV lưu kèm hồ sơ (app cũ cắt ở trình duyệt). */
    public const CV_TEXT_MAX = 20000;

    protected $fillable = [
        'full_name', 'email', 'phone', 'current_title', 'current_company', 'years_experience', 'source',
        'job_id', 'stage', 'status', 'skills', 'cv_text', 'stage_changed_at',
    ];

    protected $attributes = ['stage' => 'new', 'status' => 'active', 'skills' => '[]'];

    /** Nội dung CV không gửi ra giao diện (lớn, chỉ dùng cho tìm kiếm/đọc lại sau này). */
    protected $hidden = ['cv_text'];

    protected function casts(): array
    {
        return [
            'years_experience' => 'float',
            'skills' => 'array',
            'stage_changed_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class, 'job_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CandidateEvent::class);
    }

    /** Thư mục file của ứng viên trên đĩa `local` (storage/app/private). */
    public function cvFolder(): string
    {
        return 'cvs/'.$this->id;
    }
}
