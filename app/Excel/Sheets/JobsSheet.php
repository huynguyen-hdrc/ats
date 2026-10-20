<?php

namespace App\Excel\Sheets;

use App\Excel\Column;
use App\Excel\Sheet;
use App\Models\JobOpening;
use Illuminate\Support\Collection;

/** Vị trí tuyển dụng — cột giữ đúng SHEETS.jobs của app cũ (index.html:1104-1124). */
final class JobsSheet extends Sheet
{
    public function label(): string
    {
        return 'Vị trí tuyển dụng';
    }

    public function stem(): string
    {
        return 'vi-tri-tuyen-dung';
    }

    public function module(): string
    {
        return 'jobs';
    }

    public function importable(): bool
    {
        return true;
    }

    public function model(): string
    {
        return JobOpening::class;
    }

    public function columns(): array
    {
        return [
            new Column('Tên vị trí *', 'title', required: true, sample: 'Data Analyst'),
            new Column('Phòng ban', 'department', sample: 'Marketing'),
            new Column('Số lượng', 'headcount', 'int', default: 1, sample: '2'),
            new Column('Nơi làm việc', 'location', sample: 'HCM'),
            new Column('Loại hình', 'employment_type', 'enum', options: JobOpening::EMPLOYMENT_TYPES, sample: 'Full-time'),
            new Column('Quản lý tuyển dụng', 'hiring_manager', sample: 'Nguyễn Văn A'),
            new Column('Dải lương', 'salary_range', sample: '15-20tr'),
            new Column('Hạn tuyển xong (yyyy-mm-dd)', 'target_date', 'date', sample: '2026-12-31'),
            new Column('Đang tuyển (Có/Không)', 'is_open', 'bool', default: true, sample: 'Có'),
            new Column('Mô tả chung', 'jd_summary', 'longtext'),
            new Column('Trách nhiệm chính', 'jd_responsibilities', 'longtext'),
            new Column('Yêu cầu', 'jd_requirements', 'longtext'),
            new Column('Quyền lợi', 'jd_benefits', 'longtext'),
        ];
    }

    public function exportRows(): Collection
    {
        return JobOpening::orderBy('title')->get();
    }

    public function keyOf(array $row): ?string
    {
        $title = mb_strtolower(trim((string) ($row['title'] ?? '')));
        if ($title === '') {
            return null;
        }

        return $title.' | '.mb_strtolower(trim((string) ($row['department'] ?? '')));
    }

    public function keyLabel(): string
    {
        return 'tên vị trí + phòng ban';
    }

    public function extraOnCreate(): array
    {
        return ['created_by' => auth()->id()];
    }
}
