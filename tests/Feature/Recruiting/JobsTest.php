<?php

namespace Tests\Feature\Recruiting;

use App\Models\AuditLog;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobsTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->role('hr')->create();
    }

    public function test_hr_creates_job_with_defaults(): void
    {
        $res = $this->actingAs($this->hr())->postJson('/api/jobs', ['title' => 'Data Analyst', 'department' => ''])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Data Analyst')
            ->assertJsonPath('data.department', null)
            ->assertJsonPath('data.headcount', 1)
            ->assertJsonPath('data.is_open', true);
        $this->assertTrue(AuditLog::where('action', 'jobs.create')->where('subject_id', $res->json('data.id'))->exists());
    }

    public function test_validation(): void
    {
        $this->actingAs($this->hr())->postJson('/api/jobs', ['title' => '', 'employment_type' => 'Freelance', 'target_date' => '31/12/2026'])
            ->assertStatus(422)->assertJsonValidationErrors(['title', 'employment_type', 'target_date']);
    }

    public function test_update_and_delete(): void
    {
        $hr = $this->hr();
        $job = JobOpening::create(['title' => 'Kế toán']);
        $this->actingAs($hr)->patchJson("/api/jobs/{$job->id}", ['is_open' => false, 'target_date' => '2026-12-31'])
            ->assertOk()->assertJsonPath('data.is_open', false)->assertJsonPath('data.target_date', '2026-12-31');
        $this->deleteJson("/api/jobs/{$job->id}")->assertNoContent();
        $this->assertDatabaseMissing('jobs', ['id' => $job->id]);
    }

    public function test_staff_can_view_but_not_change(): void
    {
        $job = JobOpening::create(['title' => 'Kế toán']);
        $staff = User::factory()->create();
        $this->actingAs($staff)->getJson('/api/jobs')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/jobs', ['title' => 'X'])->assertForbidden();
        $this->patchJson("/api/jobs/{$job->id}", ['title' => 'Y'])->assertForbidden();
        $this->deleteJson("/api/jobs/{$job->id}")->assertForbidden();
    }

    public function test_guest_cannot_read_jobs(): void
    {
        $this->getJson('/api/jobs')->assertUnauthorized();
    }
}
