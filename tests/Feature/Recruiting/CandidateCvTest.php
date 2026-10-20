<?php

namespace Tests\Feature\Recruiting;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Role;
use App\Models\RolePerm;
use App\Models\User;
use App\Services\CvFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CandidateCvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function hr(): User
    {
        return User::factory()->role('hr')->create();
    }

    public function test_upload_download_replace_and_delete(): void
    {
        $c = Candidate::create(['full_name' => 'Trần Thị B']);
        $this->actingAs($this->hr())
            ->post("/api/candidates/{$c->id}/cv", ['file' => UploadedFile::fake()->createWithContent('../CV Trần Thị B (2026).pdf', '%PDF-1.4 cv')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.cv_filename', 'CV_Trần_Thị_B_2026.pdf');
        $c->refresh();
        $this->assertSame($c->id.'/CV_Trần_Thị_B_2026.pdf', $c->cv_path);
        Storage::disk('local')->assertExists('cvs/'.$c->cv_path);
        $this->assertTrue(AuditLog::where('action', 'candidates.cv_upload')->exists());

        $res = $this->get("/api/candidates/{$c->id}/cv")->assertOk();
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 cv', $res->streamedContent());

        // Tải CV khác: file cũ bị xoá, chỉ còn file mới.
        $this->post("/api/candidates/{$c->id}/cv", ['file' => UploadedFile::fake()->createWithContent('cv.docx', 'docx')], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->assertSame(['cvs/'.$c->id.'/cv.docx'], Storage::disk('local')->allFiles('cvs/'.$c->id));

        $this->deleteJson("/api/candidates/{$c->id}/cv")->assertOk()->assertJsonPath('data.cv_path', null);
        $this->assertSame([], Storage::disk('local')->allFiles('cvs/'.$c->id));
        $this->getJson("/api/candidates/{$c->id}/cv")->assertNotFound();
        $this->assertTrue(AuditLog::where('action', 'candidates.cv_delete')->exists());
    }

    public function test_file_type_and_size_limits(): void
    {
        $c = Candidate::create(['full_name' => 'A']);
        $this->actingAs($this->hr());
        $this->post("/api/candidates/{$c->id}/cv", ['file' => UploadedFile::fake()->create('cv.exe', 10)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'Chỉ nhận file CV dạng .pdf, .docx hoặc .txt.');
        $this->post("/api/candidates/{$c->id}/cv", ['file' => UploadedFile::fake()->create('cv.pdf', 15 * 1024 + 1)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'File CV tối đa 15 MB.');
        $this->post("/api/candidates/{$c->id}/cv", ['file' => UploadedFile::fake()->create('cv.txt', 15 * 1024)], ['Accept' => 'application/json'])
            ->assertCreated();
    }

    public function test_delete_candidate_purges_its_folder(): void
    {
        $c = Candidate::create(['full_name' => 'A']);
        $other = Candidate::create(['full_name' => 'B']);
        Storage::disk('local')->put("cvs/{$c->id}/a.pdf", 'x');
        Storage::disk('local')->put("cvs/{$c->id}/old.pdf", 'x');
        Storage::disk('local')->put("cvs/{$other->id}/b.pdf", 'x');
        $this->actingAs($this->hr())->deleteJson("/api/candidates/{$c->id}")->assertNoContent();
        Storage::disk('local')->assertMissing("cvs/{$c->id}/a.pdf");
        Storage::disk('local')->assertMissing("cvs/{$c->id}/old.pdf");
        Storage::disk('local')->assertExists("cvs/{$other->id}/b.pdf");
    }

    public function test_authorization(): void
    {
        $c = Candidate::create(['full_name' => 'A']);
        Storage::disk('local')->put("cvs/{$c->id}/a.pdf", 'SECRET-CV');
        $c->forceFill(['cv_path' => "{$c->id}/a.pdf", 'cv_filename' => 'a.pdf'])->save();
        $file = fn () => ['file' => UploadedFile::fake()->createWithContent('cv.pdf', 'x')];

        // Quản lý: xem/tải CV được, không tải lên/xoá được.
        $this->actingAs(User::factory()->role('manager')->create());
        $this->get("/api/candidates/{$c->id}/cv")->assertOk()->assertStreamed();
        $this->post("/api/candidates/{$c->id}/cv", $file(), ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson("/api/candidates/{$c->id}/cv")->assertForbidden();

        // Nhân viên: không gì cả.
        $this->actingAs(User::factory()->create());
        $this->get("/api/candidates/{$c->id}/cv", ['Accept' => 'application/json'])->assertForbidden();

        // Chưa đăng nhập: không có đường công khai nào tới file (selftest A9).
        auth()->logout();
        $this->get("/api/candidates/{$c->id}/cv", ['Accept' => 'application/json'])->assertUnauthorized();
        $this->get("/storage/cvs/{$c->id}/a.pdf")->assertDontSee('SECRET-CV'); // chỉ là trang SPA
        $this->get("/storage/app/private/cvs/{$c->id}/a.pdf")->assertDontSee('SECRET-CV');
    }

    public function test_create_only_role_may_attach_cv_to_own_new_candidate_only(): void
    {
        Role::create(['key' => 'nhap', 'label' => 'Nhập hồ sơ', 'base' => 'manager', 'sort' => 50, 'is_system' => false]);
        foreach (['view', 'create'] as $a) {
            RolePerm::create(['role_key' => 'nhap', 'module' => 'candidates', 'action' => $a, 'allowed' => true]);
        }
        $user = User::factory()->role('nhap')->create();
        $this->actingAs($user);
        $id = $this->postJson('/api/candidates', ['full_name' => 'Mới'])->assertCreated()->json('data.id');
        $up = fn () => $this->post("/api/candidates/{$id}/cv", ['file' => UploadedFile::fake()->createWithContent('cv.pdf', 'x')], ['Accept' => 'application/json']);
        $up()->assertCreated();
        $up()->assertForbidden(); // đã có CV: thay CV cần quyền sửa
        $someoneElse = Candidate::create(['full_name' => 'Của người khác']);
        $someoneElse->forceFill(['created_by' => User::factory()->role('hr')->create()->id])->save();
        $this->post("/api/candidates/{$someoneElse->id}/cv", ['file' => UploadedFile::fake()->createWithContent('cv.pdf', 'x')], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_safe_name(): void
    {
        $this->assertSame('passwd', CvFiles::safeName('../../etc/passwd'));
        $this->assertSame('a_b.pdf', CvFiles::safeName('a<>b.PDF'));
        $this->assertSame('cv.pdf', CvFiles::safeName('....pdf'));
    }
}
