<?php

namespace Tests\Feature\Excel;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidatesExcelTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Họ tên *', 'Email', 'Điện thoại', 'Số năm kinh nghiệm', 'Vị trí ứng tuyển (tên)', 'Vòng', 'Kỹ năng (phẩy)'];

    private function payload(array $rows, ?array $headers = null): array
    {
        return [
            'headers' => $headers ?? self::HEADERS,
            'rows' => array_map(fn ($r) => array_map(fn ($v) => is_array($v) ? $v : ['v' => $v, 's' => $v === null ? '' : (string) $v], $r), $rows),
        ];
    }

    private function hr(): User
    {
        return User::factory()->role('hr')->create();
    }

    public function test_stage_accepts_label_or_key_and_skills_split(): void
    {
        // selftest F: "Vòng tuyển dụng nhận cả tiếng Việt lẫn mã"
        $res = $this->actingAs($this->hr())->postJson('/api/excel/candidates/preview', $this->payload([
            ['Ung vien X', null, null, null, null, 'Screening', 'SQL, Excel'],
            ['Ung vien Y', null, null, null, null, 'PHONE', null],
            ['Ung vien Z', null, null, null, null, 'vong rac', null],
        ]))->assertOk()->assertJsonPath('valid', 2)->assertJsonPath('error_count', 1);
        $this->assertSame([['line' => 4, 'message' => 'vòng "vong rac" không hợp lệ']], $res->json('errors'));

        $this->postJson('/api/excel/candidates/import', $this->payload([
            ['Ung vien X', null, null, null, null, 'Screening', 'SQL, Excel'],
            ['Ung vien Y', null, null, null, null, 'PHONE', null],
        ]))->assertOk()->assertJsonPath('inserted', 2);
        $x = Candidate::where('full_name', 'Ung vien X')->sole();
        $this->assertSame(['screening', ['SQL', 'Excel']], [$x->stage, $x->skills]);
        $this->assertSame('phone', Candidate::where('full_name', 'Ung vien Y')->sole()->stage);
    }

    public function test_job_lookup_errors_and_tie_break_by_department(): void
    {
        JobOpening::create(['title' => 'Data Analyst', 'department' => 'Marketing']);
        JobOpening::create(['title' => 'Data Analyst', 'department' => 'Finance']);
        JobOpening::create(['title' => 'Kế toán', 'department' => 'Finance']);

        $res = $this->actingAs($this->hr())->postJson('/api/excel/candidates/preview', $this->payload([
            ['A', null, null, null, 'Data Analyst', null, null],
            ['B', null, null, null, 'Không có', null, null],
            ['C', null, null, null, 'data analyst (finance)', null, null],
            ['D', null, null, null, 'Data Analyst [Marketing]', null, null],
            ['E', null, null, null, 'kế toán', null, null],
            ['F', null, null, null, 'Kế toán (Sales)', null, null],
        ]))->assertOk()->assertJsonPath('valid', 3);
        $errors = collect($res->json('errors'))->pluck('message', 'line')->all();
        $this->assertSame('có 2 vị trí trùng tên "Data Analyst" — ghi rõ dạng "Data Analyst (Marketing)". Đang có: Marketing / Finance', $errors[2]);
        $this->assertSame('không tìm thấy vị trí "Không có"', $errors[3]);
        $this->assertSame('không tìm thấy vị trí "Kế toán (Sales)"', $errors[7]);
    }

    public function test_import_dedups_by_email_keeps_phone_zero_and_writes_events(): void
    {
        $job = JobOpening::create(['title' => 'Data Analyst', 'department' => 'Marketing']);
        $existing = Candidate::create(['full_name' => 'Cũ', 'email' => 'b.tran@gmail.com', 'source' => 'TopCV', 'stage' => 'phone']);

        $this->actingAs($this->hr())->postJson('/api/excel/candidates/import', $this->payload([
            ['Trần Thị B', ' B.Tran@Gmail.com ', ['v' => 912345678, 's' => '912345678'], '2,8', 'Data Analyst', 'Interview', 'SQL, Excel, Power BI'],
            ['Lê Văn C', 'c@x.com', ['v' => 901234567, 's' => '0901234567'], null, null, null, null],
            ['Lê Văn C', 'C@X.COM', null, 3, null, 'hired', null],        // trùng ngay trong file → cập nhật dòng trên
            ['Không email', null, null, null, null, null, null],
        ]))->assertOk()->assertJsonPath('inserted', 2)->assertJsonPath('updated', 2);

        $existing->refresh();
        $this->assertSame('Trần Thị B', $existing->full_name);
        $this->assertSame('B.Tran@Gmail.com', $existing->email); // như app cũ: cột có trong file thì ghi đè
        $this->assertSame('0912345678', $existing->phone);
        $this->assertSame(2.8, $existing->years_experience);
        $this->assertSame($job->id, $existing->job_id);
        $this->assertSame('interview', $existing->stage);
        $this->assertSame(['SQL', 'Excel', 'Power BI'], $existing->skills);
        $this->assertSame('TopCV', $existing->source); // cột không có trong file: giữ nguyên
        $this->assertTrue($existing->events()->where('type', 'stage_change')->where('to_stage', 'interview')->exists());

        $c = Candidate::where('full_name', 'Lê Văn C')->sole();
        $this->assertSame('0901234567', $c->phone);
        $this->assertSame('hired', $c->stage);
        $this->assertEquals(3, $c->years_experience);
        $this->assertSame('new', Candidate::where('full_name', 'Không email')->sole()->stage); // mặc định vòng new
        $this->assertTrue(AuditLog::where('action', 'candidates.import')->exists());
        $this->assertSame(1, Candidate::where('full_name', 'Không email')->sole()->events()->where('type', 'created')->count());
    }

    public function test_import_rolls_back_on_any_error(): void
    {
        $this->actingAs($this->hr())->postJson('/api/excel/candidates/import', $this->payload([
            ['Một', 'mot@x.com', null, null, null, null, null],
            ['Hai', 'hai@x.com', null, 'nhiều', null, null, null],
        ]))->assertStatus(422);
        $this->assertSame(0, Candidate::count());
    }

    public function test_preview_counts_duplicates_by_email(): void
    {
        Candidate::create(['full_name' => 'Cũ', 'email' => 'a@x.com']);
        $this->actingAs($this->hr())->postJson('/api/excel/candidates/preview', $this->payload([
            ['A', 'A@x.com', null, null, null, null, null],
            ['B', 'b@x.com', null, null, null, null, null],
            ['B2', 'b@x.com', null, null, null, null, null],
            ['N', null, null, null, null, null, null],
        ]))->assertOk()->assertJsonPath('duplicates', 2)->assertJsonPath('key_label', 'email');
    }

    public function test_export_and_template(): void
    {
        $job = JobOpening::create(['title' => 'Data Analyst']);
        Candidate::create(['full_name' => 'Trần Thị B', 'email' => 'b@x.com', 'phone' => '0912345678', 'job_id' => $job->id,
            'stage' => 'screening', 'skills' => ['SQL', 'Excel'], 'years_experience' => 2.8]);
        $this->actingAs(User::factory()->role('manager')->create())->getJson('/api/excel/candidates/export')->assertOk()
            ->assertJsonPath('filename', 'ung-vien-'.now()->format('Y-m-d').'.xlsx')
            ->assertJsonPath('headers.6', 'Vị trí ứng tuyển (tên)')
            ->assertJsonPath('rows.0.2', '0912345678')
            ->assertJsonPath('rows.0.5', 2.8)
            ->assertJsonPath('rows.0.6', 'Data Analyst')
            ->assertJsonPath('rows.0.7', 'Screening')
            ->assertJsonPath('rows.0.9', 'SQL, Excel');
        $t = $this->getJson('/api/excel/candidates/template')->assertOk()
            ->assertJsonPath('filename', 'mau-ung-vien.xlsx')->assertJsonPath('headers.0', 'Họ tên *')
            ->assertJsonPath('sample.7', 'New');
        $this->assertContains('Vòng: New / Screening / Phone screen / Interview / Test / Offer / Hired', $t->json('guide'));
        $this->assertContains('Vị trí ứng tuyển (tên): phải trùng tên đã có trong app', $t->json('guide'));
    }

    public function test_permissions(): void
    {
        $this->actingAs(User::factory()->role('manager')->create())
            ->postJson('/api/excel/candidates/import', $this->payload([['A', null, null, null, null, null, null]]))->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/api/excel/candidates/export')->assertForbidden();
    }
}
