<?php

namespace App\Excel\Sheets;

use App\Excel\Column;
use App\Excel\ImportError;
use App\Excel\Sheet;
use App\Models\Candidate;
use App\Models\JobOpening;
use App\Models\Stage;
use Illuminate\Support\Collection;

/** Ứng viên — cột giữ đúng SHEETS.candidates của app cũ (index.html:1086-1103). Trạng thái, CV không có trong Excel. */
final class CandidatesSheet extends Sheet
{
    /** @var Collection<int, JobOpening>|null nạp một lần cho cả file */
    private ?Collection $jobs = null;

    public function label(): string
    {
        return 'Ứng viên';
    }

    public function stem(): string
    {
        return 'ung-vien';
    }

    public function module(): string
    {
        return 'candidates';
    }

    public function importable(): bool
    {
        return true;
    }

    public function model(): string
    {
        return Candidate::class;
    }

    public function columns(): array
    {
        return [
            new Column('Họ tên *', 'full_name', required: true, sample: 'Trần Thị B'),
            new Column('Email', 'email', sample: 'b.tran@gmail.com'),
            new Column('Điện thoại', 'phone', 'phone', sample: '0912345678'),
            new Column('Chức danh hiện tại', 'current_title', sample: 'Data Analyst'),
            new Column('Công ty hiện tại', 'current_company', sample: 'ACB'),
            new Column('Số năm kinh nghiệm', 'years_experience', 'num', sample: '2.8'),
            new Column('Vị trí ứng tuyển (tên)', 'job_id', 'lookup',
                lookup: fn (string $v) => $this->pickJob($v),
                display: fn ($id) => $this->jobs()->firstWhere('id', $id)?->title ?? '',
                sample: 'Data Analyst'),
            new Column('Vòng', 'stage', 'stage', default: 'new',
                options: Stage::ordered()->pluck('label', 'key')->all(), sample: 'New'),
            new Column('Nguồn', 'source', sample: 'TopCV'),
            new Column('Kỹ năng (phẩy)', 'skills', 'list', sample: 'SQL, Excel, Power BI'),
        ];
    }

    public function exportRows(): Collection
    {
        return Candidate::orderByDesc('stage_changed_at')->orderBy('full_name')->get();
    }

    public function keyOf(array $row): ?string
    {
        $email = mb_strtolower(trim((string) ($row['email'] ?? '')));

        return $email === '' ? null : $email;
    }

    public function keyLabel(): string
    {
        return 'email';
    }

    private function jobs(): Collection
    {
        return $this->jobs ??= JobOpening::orderBy('title')->get(['id', 'title', 'department']);
    }

    /**
     * Tra vị trí theo tên: trùng tên thì báo lỗi thay vì lấy dòng đầu. Cho phép "Tên (phòng ban)" hoặc
     * "Tên [phòng ban]" để tách (app cũ: pickOne, index.html:1267-1282).
     */
    private function pickJob(string $raw): string
    {
        $low = fn ($s) => mb_strtolower(trim((string) $s));
        $m = null;
        $hasTie = (bool) preg_match('/^(.*?)\s*[(\[]([^)\]]+)[)\]]\s*$/u', $raw, $m);
        $name = $low($hasTie ? $m[1] : $raw);
        $tie = $hasTie ? $low($m[2]) : null;

        $hits = $this->jobs()->filter(fn ($j) => $low($j->title) === $name);
        if ($tie !== null) {
            $hits = $hits->filter(fn ($j) => $low($j->department) === $tie);
        }
        $hits = $hits->values();
        if ($hits->isEmpty()) {
            throw new ImportError("không tìm thấy vị trí \"{$raw}\"");
        }
        if ($hits->count() > 1) {
            $opts = $hits->pluck('department')->filter()->implode(' / ') ?: 'không có dữ liệu phân biệt';
            $first = $hits[0]->department ?: '...';
            throw new ImportError("có {$hits->count()} vị trí trùng tên \"{$raw}\" — ghi rõ dạng \"{$raw} ({$first})\". Đang có: {$opts}");
        }

        return $hits[0]->id;
    }
}
