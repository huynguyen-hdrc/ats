<?php

namespace Tests\Feature\Recruiting;

use App\Models\Candidate;
use App\Models\CandidateEvent;
use App\Models\JobOpening;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\StageSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Selftest nhóm B của app cũ (selftest.html:132-201) — ràng buộc ở tầng dữ liệu/model. */
class CandidateLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function make(array $attrs = []): Candidate
    {
        $job = JobOpening::create(['title' => 'Vi tri test', 'department' => 'QA', 'headcount' => 2, 'is_open' => true]);

        return Candidate::create($attrs + ['full_name' => 'Ung vien', 'job_id' => $job->id, 'skills' => ['SQL', 'Excel'], 'years_experience' => 3.5]);
    }

    public function test_seven_stages_are_seeded_in_order(): void
    {
        $keys = Stage::ordered()->pluck('key')->all();
        $this->assertCount(7, $keys);
        $this->assertSame('new', $keys[0]);
        $this->assertSame('hired', end($keys));
        foreach (['phone', 'screening'] as $k) {
            $this->assertContains($k, $keys);
        }
        $this->assertSame('Screening', Stage::find('screening')->label);
        (new StageSeeder)->run(); // chạy lại không nhân bản
        $this->assertSame(7, Stage::count());
    }

    public function test_b2_b3_default_stage_and_created_event(): void
    {
        $c = $this->make()->fresh();
        $this->assertSame('new', $c->stage);
        $this->assertSame('active', $c->status);
        $this->assertSame(['SQL', 'Excel'], $c->skills);
        $this->assertSame(3.5, $c->years_experience);
        $this->assertTrue($c->events()->where('type', 'created')->exists());
    }

    public function test_b4_b5_b6_stage_change_is_logged_and_dates_behave(): void
    {
        $c = $this->make();
        $this->travel(5)->minutes();
        $c->update(['stage' => 'phone']);
        $ev = $c->events()->where('type', 'stage_change')->get();
        $this->assertCount(1, $ev);
        $this->assertSame(['new', 'phone'], [$ev[0]->from_stage, $ev[0]->to_stage]);
        $c->refresh();
        $this->assertTrue($c->stage_changed_at->gt($c->created_at));

        $before = $c->stage_changed_at->toIso8601String();
        $this->travel(5)->minutes();
        $c->update(['phone' => '0900000000']);
        $this->assertSame($before, $c->fresh()->stage_changed_at->toIso8601String());
        $this->assertSame(1, $c->events()->where('type', 'stage_change')->count());
    }

    public function test_b7_status_change_is_logged(): void
    {
        $c = $this->make();
        $c->update(['status' => 'rejected']);
        $ev = $c->events()->where('type', 'status_change')->get();
        $this->assertCount(1, $ev);
        $this->assertSame('rejected', $ev[0]->body);
    }

    public function test_b8_unknown_stage_is_rejected_by_the_database(): void
    {
        $c = $this->make();
        $this->expectException(QueryException::class);
        $c->update(['stage' => 'khong_co_vong_nay']);
    }

    public function test_b9_invalid_status_is_rejected_by_the_database(): void
    {
        $c = $this->make();
        $this->expectException(QueryException::class);
        $c->update(['status' => 'linh_tinh']);
    }

    public function test_b10_deleting_job_keeps_candidate_with_null_job(): void
    {
        $c = $this->make();
        $tmp = JobOpening::create(['title' => 'Vi tri tam']);
        $c->update(['job_id' => $tmp->id]);
        $this->actingAs(User::factory()->role('hr')->create())->deleteJson("/api/jobs/{$tmp->id}")->assertNoContent();
        $this->assertNotNull($c->fresh());
        $this->assertNull($c->fresh()->job_id);
    }

    public function test_b11_note_round_trip_and_events_cascade_on_delete(): void
    {
        $c = $this->make();
        CandidateEvent::create(['candidate_id' => $c->id, 'type' => 'note', 'body' => 'Ghi chú kiểm thử']);
        $this->assertTrue($c->events()->where('type', 'note')->where('body', 'Ghi chú kiểm thử')->exists());
        $c->delete();
        $this->assertSame(0, CandidateEvent::where('candidate_id', $c->id)->count());
    }
}
