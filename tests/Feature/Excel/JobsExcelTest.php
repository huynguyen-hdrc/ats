<?php

namespace Tests\Feature\Excel;

use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobsExcelTest extends TestCase
{
    use RefreshDatabase;

    private function cells(array $values): array
    {
        return array_map(fn ($v) => ['v' => $v, 's' => is_null($v) ? '' : (string) $v], $values);
    }

    private function payload(array $rows, ?array $headers = null): array
    {
        return [
            'headers' => $headers ?? ['Tên vị trí *', 'Phòng ban', 'Số lượng', 'Hạn tuyển xong (yyyy-mm-dd)', 'Đang tuyển (Có/Không)', 'Loại hình'],
            'rows' => array_map(fn ($r) => $this->cells($r), $rows),
        ];
    }

    public function test_preview_reports_errors_with_sheet_line_numbers_and_duplicates(): void
    {
        JobOpening::create(['title' => 'Data Analyst', 'department' => 'Marketing']);
        $hr = User::factory()->role('hr')->create();

        $res = $this->actingAs($hr)->postJson('/api/excel/jobs/preview', $this->payload([
            ['data analyst', 'MARKETING', 2, '2026-12-31', 'Có', 'Full-time'],   // trùng bản ghi có sẵn
            ['', 'Sales', 1, null, null, null],                                  // thiếu tên
            [null, null, null, null, null, null],                                // dòng trống: bỏ qua
            ['Kế toán', 'Finance', 'hai', '31/02/2026', 'Không', 'Freelance'],  // 3 lỗi
            ['Kế toán', 'Finance', 1, '01/11/2026', 'Không', 'part-time'],
        ]))->assertOk();

        $res->assertJsonPath('valid', 2)->assertJsonPath('error_count', 2)->assertJsonPath('duplicates', 1);
        $this->assertSame([3, 5], array_column($res->json('errors'), 'line'));
        $this->assertStringContainsString('thiếu "Tên vị trí"', $res->json('errors.0.message'));
        $this->assertStringContainsString('Loại hình', $res->json('errors.1.message'));
    }

    public function test_import_upserts_by_title_and_department_and_is_atomic(): void
    {
        $existing = JobOpening::create(['title' => 'Data Analyst', 'department' => 'Marketing', 'location' => 'HN']);
        $hr = User::factory()->role('hr')->create();

        $this->actingAs($hr)->postJson('/api/excel/jobs/import', $this->payload([
            ['Data Analyst', 'marketing', 3, '2026-12-31', 'Có', 'Full-time'],
            ['Kế toán', 'Finance', null, '01/11/2026', 'Không', 'part-time'],
            ['Kế toán', 'Finance', 2, null, null, null],      // trùng ngay trong file → cập nhật dòng trên
        ]))->assertOk()->assertJsonPath('inserted', 1)->assertJsonPath('updated', 2);

        $existing->refresh();
        $this->assertSame(3, $existing->headcount);
        $this->assertSame('HN', $existing->location); // cột không có trong file: giữ nguyên
        $ke = JobOpening::where('title', 'Kế toán')->sole();
        $this->assertSame(2, $ke->headcount);
        $this->assertFalse($ke->is_open);
        $this->assertSame('Part-time', $ke->employment_type);
        $this->assertSame('2026-11-01', $ke->target_date->format('Y-m-d'));

        // Có một dòng lỗi → không ghi gì cả.
        $this->postJson('/api/excel/jobs/import', $this->payload([
            ['Mới 1', 'X', 1, null, null, null],
            ['Mới 2', 'X', 'abc', null, null, null],
        ]))->assertStatus(422);
        $this->assertDatabaseMissing('jobs', ['title' => 'Mới 1']);
    }

    public function test_missing_required_column_rejects_file(): void
    {
        $hr = User::factory()->role('hr')->create();
        $this->actingAs($hr)->postJson('/api/excel/jobs/preview', $this->payload([['Marketing']], ['Phòng ban']))
            ->assertOk()->assertJsonPath('errors.0.line', 1)
            ->assertJsonPath('errors.0.message', 'thiếu cột bắt buộc: Tên vị trí');
    }

    public function test_export_and_template(): void
    {
        JobOpening::create(['title' => 'Data Analyst', 'is_open' => false, 'target_date' => '2026-12-31']);
        $staff = User::factory()->create();
        $this->actingAs($staff)->getJson('/api/excel/jobs/export')->assertOk()
            ->assertJsonPath('headers.0', 'Tên vị trí')
            ->assertJsonPath('rows.0.0', 'Data Analyst')
            ->assertJsonPath('rows.0.8', 'Không')
            ->assertJsonPath('rows.0.7', '2026-12-31');
        $this->getJson('/api/excel/jobs/template')->assertOk()->assertJsonPath('headers.0', 'Tên vị trí *');
    }

    public function test_import_needs_create_and_edit(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff)->postJson('/api/excel/jobs/import', $this->payload([['A', 'B', 1, null, null, null]]))->assertForbidden();
        $this->getJson('/api/excel/nope/export')->assertNotFound();
    }
}
