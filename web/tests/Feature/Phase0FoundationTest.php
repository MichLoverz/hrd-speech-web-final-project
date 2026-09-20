<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Criterion;
use App\Models\InterviewSession;
use App\Models\JobOpening;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 0 definition of done: an HRD user can create a job opening, its
 * criteria and questions, and a candidate, all through the web UI.
 */
class Phase0FoundationTest extends TestCase
{
    use RefreshDatabase;

    private function hrd(): User
    {
        return User::factory()->create(['role' => User::ROLE_HRD]);
    }

    public function test_hrd_can_build_an_opening_end_to_end(): void
    {
        $hrd = $this->hrd();

        $this->actingAs($hrd)
            ->post(route('job-openings.store'), [
                'title' => 'Backend Developer',
                'department' => 'Engineering',
                'salary_min' => 7_000_000,
                'salary_max' => 11_000_000,
                'status' => 'open',
            ])
            ->assertRedirect();

        $opening = JobOpening::firstOrFail();
        $this->assertSame($hrd->id, $opening->created_by);

        $this->actingAs($hrd)
            ->post(route('criteria.store', $opening), ['name' => 'Pengalaman teknis', 'weight' => 3])
            ->assertRedirect();

        $criterion = Criterion::firstOrFail();

        $this->actingAs($hrd)
            ->post(route('questions.store', $criterion), ['text' => 'Ceritakan proyek terakhir Anda.'])
            ->assertRedirect();

        $this->assertSame(1, Question::firstOrFail()->position);

        Storage::fake('local');

        $this->actingAs($hrd)
            ->post(route('candidates.store'), [
                'job_opening_id' => $opening->id,
                'name' => 'Rina Sulistyawati',
                'email' => 'rina@example.com',
                'expected_salary' => 8_000_000,
                'status' => 'applied',
                'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect();

        $candidate = Candidate::firstOrFail();
        $this->assertNotNull($candidate->cv_path);
        Storage::disk('local')->assertExists($candidate->cv_path);

        // The nested relation chain used by the session page resolves.
        $this->assertSame(1, $opening->questions()->count());

        $this->actingAs($hrd)
            ->get(route('job-openings.show', $opening))
            ->assertOk()
            ->assertSee('Pengalaman teknis')
            ->assertSee('Ceritakan proyek terakhir Anda.')
            ->assertSee('Rina Sulistyawati');
    }

    public function test_validation_rejects_salary_range_that_is_upside_down(): void
    {
        $this->actingAs($this->hrd())
            ->from(route('job-openings.create'))
            ->post(route('job-openings.store'), [
                'title' => 'X',
                'salary_min' => 10_000_000,
                'salary_max' => 5_000_000,
                'status' => 'draft',
            ])
            ->assertRedirect(route('job-openings.create'))
            ->assertSessionHasErrors('salary_max');
    }

    public function test_cv_must_be_a_pdf(): void
    {
        $opening = JobOpening::factory()->for($this->hrd(), 'creator')->create();

        $this->actingAs($opening->creator)
            ->post(route('candidates.store'), [
                'job_opening_id' => $opening->id,
                'name' => 'X',
                'status' => 'applied',
                'cv' => UploadedFile::fake()->create('cv.exe', 10, 'application/octet-stream'),
            ])
            ->assertSessionHasErrors('cv');
    }

    public function test_hrd_cannot_edit_another_hrds_opening(): void
    {
        $opening = JobOpening::factory()->for($this->hrd(), 'creator')->create();
        $other = $this->hrd();

        $this->actingAs($other)->get(route('job-openings.edit', $opening))->assertForbidden();
        $this->actingAs($other)->post(route('criteria.store', $opening), ['name' => 'X', 'weight' => 1])->assertForbidden();
        $this->actingAs($other)->get(route('job-openings.index'))->assertOk()->assertDontSee($opening->title);
    }

    public function test_interviewer_can_read_but_not_write(): void
    {
        $opening = JobOpening::factory()->for($this->hrd(), 'creator')->create();
        $interviewer = User::factory()->create(['role' => User::ROLE_INTERVIEWER]);

        $this->actingAs($interviewer)->get(route('job-openings.show', $opening))->assertOk();
        $this->actingAs($interviewer)->get(route('job-openings.create'))->assertForbidden();
        $this->actingAs($interviewer)->post(route('criteria.store', $opening), ['name' => 'X', 'weight' => 1])->assertForbidden();
    }

    public function test_admin_sees_everything(): void
    {
        $opening = JobOpening::factory()->for($this->hrd(), 'creator')->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('job-openings.edit', $opening))->assertOk();
    }

    public function test_session_draft_puts_lead_interviewer_on_panel(): void
    {
        $hrd = $this->hrd();
        $opening = JobOpening::factory()->for($hrd, 'creator')->create();
        $candidate = Candidate::factory()->for($opening)->create(['status' => 'applied']);
        $panelist = User::factory()->create(['role' => User::ROLE_INTERVIEWER]);

        $this->actingAs($hrd)
            ->post(route('interview-sessions.store'), [
                'candidate_id' => $candidate->id,
                'interviewer_id' => $hrd->id,
                'panel' => [$panelist->id],
            ])
            ->assertRedirect();

        $session = InterviewSession::firstOrFail();
        $this->assertSame('draft', $session->status);
        $this->assertEqualsCanonicalizing([$hrd->id, $panelist->id], $session->panel->pluck('id')->all());
        $this->assertSame('interviewing', $candidate->fresh()->status);
        $this->assertNull($session->analysisResult);

        $this->actingAs($panelist)->get(route('interview-sessions.show', $session))->assertOk();
    }

    public function test_seeder_runs_without_fabricated_evaluation_data(): void
    {
        $this->seed();

        $this->assertSame(2, JobOpening::count());
        $this->assertSame(0, \App\Models\TranscriptSegment::count());
        $this->assertSame(0, \App\Models\AnalysisResult::count());
    }
}
