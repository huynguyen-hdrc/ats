<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Vị trí tuyển dụng (bảng `jobs`). Tên lớp tránh nhầm với job của hàng đợi. */
class JobOpening extends Model
{
    use HasUuids;

    protected $table = 'jobs';

    public const EMPLOYMENT_TYPES = ['Full-time', 'Part-time', 'Thực tập', 'Thời vụ'];

    protected $fillable = [
        'title', 'department', 'headcount', 'location', 'employment_type', 'hiring_manager',
        'salary_range', 'target_date', 'is_open', 'jd_summary', 'jd_responsibilities',
        'jd_requirements', 'jd_benefits', 'created_by',
    ];

    protected $attributes = ['headcount' => 1, 'is_open' => true];

    protected function casts(): array
    {
        return ['headcount' => 'integer', 'is_open' => 'boolean', 'target_date' => 'date:Y-m-d'];
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class, 'job_id');
    }
}
