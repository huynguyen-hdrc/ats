<?php

namespace Tests\Feature\Recruiting;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\JobOpening;
use App\Models\Role;
use App\Models\RolePerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidatesTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->role('hr')->create();
    }

    private function cand(array $attrs = []): Candidate
    {
        return Candidate::create($attrs + ['full_name' => 'Trần Thị B', 'email' => 'b@x.com']);
    }

    public function test_hr_creates_candidate_with_defaults_and_cv_text_truncated(): void
    {
        $job = JobOpening::create(['title' => 'Data Analyst']);
        $res = $this->actingAs($this->hr())->postJson('/api/candidates', [
            'full_name' => 'Trần Thị B', 'email' => '', 'job_id' => $job->id, 'years_experience' => 2.8,
            'skills' => ['SQL', ' ', 'Excel '], 'cv_text' => str_repeat('a', 25000),
        ])->assertCreated()
            ->assertJsonPath('data.stage', 'new')->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.email', null)->assertJsonPath('data.skills', ['SQL', 'Excel'])
            ->assertJsonPath('data.years_experience', 2.8)->assertJsonPath('data.job.title', 'Data Analyst')
            ->assertJsonMissingPath('data.cv_text');
        $c = Candidate::find($res->json('data.id'));
        $this->assertSame(20000, mb_strlen($c->cv_text));
        $this->assertTrue($c->events()->where('type', 'created')->exists());
        $this->assertTrue(AuditLog::where('action', 'candidates.create')->where('subject_id', $c->id)->exists());
    }

    public function test_validation_messages_are_vietnamese(): void
    {
        $this->actingAs($this->hr())->postJson('/api/candidates', [
            'full_name' => '', 'email' => 'khong-phai-email', 'years_experience' => 'abc',
            'stage' => 'khong_co', 'status' => 'linh_tinh', 'job_id' => '00000000-0000-0000-0000-000000000000',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['full_name', 'email', 'years_experience', 'stage', 'status', 'job_id'])
            ->assertJsonPath('errors.full_name.0', 'Cần nhập họ tên.')
            ->assertJsonPath('errors.stage.0', 'vòng không tồn tại.');
    }

    public function test_list_is_ordered_by_stage_changed_at_and_filters_by_job(): void
    {
        $job = JobOpening::create(['title' => 'Kế toán']);
        $a = $this->cand(['full_name' => 'A']);
        $this->travel(1)->minutes();
        $b = $this->cand(['full_name' => 'B', 'job_id' => $job->id, 'status' => 'rejected']);
        $this->actingAs($this->hr())->getJson('/api/candidates')->assertOk()
            ->assertJsonPath('data.0.id', $b->id)->assertJsonPath('data.1.id', $a->id);
        $this->getJson("/api/candidates?job_id={$job->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_update_and_show_with_events(): void
    {
        $c = $this->cand();
        $hr = $this->hr();
        $this->actingAs($hr)->patchJson("/api/candidates/{$c->id}", ['phone' => '0912345678', 'stage' => 'interview'])
            ->assertOk()->assertJsonPath('data.phone', '0912345678')->assertJsonPath('data.stage', 'interview');
        $this->getJson("/api/candidates/{$c->id}")->assertOk()
            ->assertJsonPath('events.0.type', 'stage_change')->assertJsonPath('events.0.to_stage', 'interview')
            ->assertJsonPath('events.0.author', $hr->name)->assertJsonPath('events.1.type', 'created');
        $this->assertTrue(AuditLog::where('action', 'candidates.stage')->exists());
        $this->patchJson("/api/candidates/{$c->id}", ['cv_text' => 'x'])->assertStatus(422);
    }

    public function test_stage_change_endpoint_writes_event_and_audit(): void
    {
        $c = $this->cand();
        $this->travel(2)->minutes();
        $this->actingAs($this->hr())->patchJson("/api/candidates/{$c->id}/stage", ['stage' => 'phone'])
            ->assertOk()->assertJsonPath('data.stage', 'phone');
        $c->refresh();
        $this->assertTrue($c->stage_changed_at->gt($c->created_at));
        $ev = $c->events()->where('type', 'stage_change')->sole();
        $this->assertSame(['new', 'phone'], [$ev->from_stage, $ev->to_stage]);
        $this->assertTrue(AuditLog::where('action', 'candidates.stage')->where('subject_id', $c->id)->exists());

        // Cùng vòng: không ghi thêm sự kiện.
        $this->patchJson("/api/candidates/{$c->id}/stage", ['stage' => 'phone'])->assertOk();
        $this->assertSame(1, $c->events()->where('type', 'stage_change')->count());
        $this->patchJson("/api/candidates/{$c->id}/stage", ['stage' => 'khong_co'])->assertStatus(422);
    }

    public function test_reject_and_reopen(): void
    {
        $c = $this->cand();
        $this->actingAs($this->hr())->patchJson("/api/candidates/{$c->id}/status", ['status' => 'rejected'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->patchJson("/api/candidates/{$c->id}/status", ['status' => 'active'])->assertOk();
        $this->assertSame(['active', 'rejected'], $c->events()->where('type', 'status_change')
            ->orderByDesc('created_at')->orderByDesc('id')->pluck('body')->all());
        $this->assertSame(2, AuditLog::where('action', 'candidates.status')->count());
        $this->patchJson("/api/candidates/{$c->id}/status", ['status' => 'linh_tinh'])->assertStatus(422);
    }

    public function test_note_requires_comment_permission(): void
    {
        $c = $this->cand();
        $manager = User::factory()->role('manager')->create();
        $this->actingAs($manager)->postJson("/api/candidates/{$c->id}/notes", ['body' => "Phỏng vấn tốt\nLương 20tr"])
            ->assertCreated()->assertJsonPath('events.0.type', 'note')->assertJsonPath('events.0.body', "Phỏng vấn tốt\nLương 20tr");
        $this->postJson("/api/candidates/{$c->id}/notes", ['body' => '   '])->assertStatus(422);

        RolePerm::where('role_key', 'manager')->where('module', 'candidates')->where('action', 'comment')->update(['allowed' => false]);
        $this->actingAs(User::find($manager->id))->postJson("/api/candidates/{$c->id}/notes", ['body' => 'x'])->assertForbidden();
    }

    public function test_delete_removes_candidate_events_and_is_audited(): void
    {
        $c = $this->cand();
        $this->actingAs($this->hr())->deleteJson("/api/candidates/{$c->id}")->assertNoContent();
        $this->assertDatabaseMissing('candidates', ['id' => $c->id]);
        $this->assertDatabaseMissing('candidate_events', ['candidate_id' => $c->id]);
        $this->assertTrue(AuditLog::where('action', 'candidates.delete')->where('subject_id', $c->id)->exists());
    }

    public function test_manager_can_view_and_comment_only(): void
    {
        $c = $this->cand();
        $this->actingAs(User::factory()->role('manager')->create());
        $this->getJson('/api/candidates')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/candidates/{$c->id}")->assertOk();
        $this->getJson('/api/stages')->assertOk()->assertJsonCount(7, 'data');
        $this->postJson('/api/candidates', ['full_name' => 'X'])->assertForbidden();
        $this->patchJson("/api/candidates/{$c->id}", ['full_name' => 'Y'])->assertForbidden();
        $this->patchJson("/api/candidates/{$c->id}/stage", ['stage' => 'phone'])->assertForbidden(); // kéo thả cũng cần quyền sửa
        $this->patchJson("/api/candidates/{$c->id}/status", ['status' => 'rejected'])->assertForbidden();
        $this->deleteJson("/api/candidates/{$c->id}")->assertForbidden();
        $this->postJson('/api/candidates/parse-cv', ['text' => str_repeat('x', 50)])->assertForbidden();
        $this->assertSame('new', $c->fresh()->stage);
    }

    public function test_staff_is_forbidden_everywhere(): void
    {
        $c = $this->cand();
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/candidates')->assertForbidden();
        $this->getJson("/api/candidates/{$c->id}")->assertForbidden();
        $this->getJson('/api/stages')->assertForbidden();
        $this->postJson('/api/candidates', ['full_name' => 'X'])->assertForbidden();
        $this->patchJson("/api/candidates/{$c->id}/stage", ['stage' => 'phone'])->assertForbidden();
        $this->postJson("/api/candidates/{$c->id}/notes", ['body' => 'x'])->assertForbidden();
        $this->deleteJson("/api/candidates/{$c->id}")->assertForbidden();
        $this->getJson("/api/candidates/{$c->id}/cv")->assertForbidden();
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/candidates')->assertUnauthorized();
        $this->postJson('/api/candidates/parse-cv', ['text' => str_repeat('x', 50)])->assertUnauthorized(); // selftest A8
    }

    public function test_custom_role_matrix_is_respected(): void
    {
        Role::create(['key' => 'recruiter', 'label' => 'Tuyển dụng', 'base' => 'staff', 'sort' => 50, 'is_system' => false]);
        foreach (['view', 'edit'] as $a) {
            RolePerm::create(['role_key' => 'recruiter', 'module' => 'candidates', 'action' => $a, 'allowed' => true]);
        }
        $c = $this->cand();
        $this->actingAs(User::factory()->role('recruiter')->create());
        $this->patchJson("/api/candidates/{$c->id}/stage", ['stage' => 'offer'])->assertOk();
        $this->postJson('/api/candidates', ['full_name' => 'X'])->assertForbidden();
    }

    public function test_job_cards_get_counts_only_for_users_who_see_candidates(): void
    {
        $job = JobOpening::create(['title' => 'Data Analyst', 'headcount' => 2]);
        $this->cand(['job_id' => $job->id]);
        $this->cand(['job_id' => $job->id, 'stage' => 'hired', 'status' => 'rejected']);
        $this->cand(['job_id' => $job->id, 'status' => 'withdrawn']);

        $this->actingAs($this->hr())->getJson('/api/jobs')->assertOk()
            ->assertJsonPath('data.0.candidates_count', 1)->assertJsonPath('data.0.hired_count', 1);
        $this->getJson("/api/jobs/{$job->id}")->assertJsonPath('data.hired_count', 1);

        $this->actingAs(User::factory()->create())->getJson('/api/jobs')->assertOk()
            ->assertJsonMissingPath('data.0.candidates_count');
    }
}
