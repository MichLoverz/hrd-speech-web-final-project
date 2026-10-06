<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\InterviewSession;
use App\Models\JobOpening;
use App\Models\ManualAssessment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1: admin role management, session filters, manual (human) scoring
 * with blind rating, and the candidate comparison page.
 */
class Phase1CrudTest extends TestCase
{
    use RefreshDatabase;

    private function openingWithCriteria(User $hrd): JobOpening
    {
        $opening = JobOpening::factory()->for($hrd, 'creator')->create(['salary_min' => 5_000_000, 'salary_max' => 8_000_000]);
        $a = $opening->criteria()->create(['name' => 'Teknis', 'weight' => 3]);
        $b = $opening->criteria()->create(['name' => 'Komunikasi', 'weight' => 1]);
        $a->questions()->create(['text' => 'Q1', 'position' => 1]);
        $b->questions()->create(['text' => 'Q2', 'position' => 1]);

        return $opening;
    }

    private function sessionFor(JobOpening $opening, User $lead, array $panel = []): InterviewSession
    {
        $candidate = Candidate::factory()->for($opening)->create();
        $session = InterviewSession::create(['candidate_id' => $candidate->id, 'interviewer_id' => $lead->id]);
        $session->panel()->sync([$lead->id, ...array_map(fn ($u) => $u->id, $panel)]);

        return $session;
    }

    public function test_admin_can_change_roles_but_not_demote_self(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_HRD]);

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertSee($user->email);
        $this->actingAs($admin)->patch(route('admin.users.role', $user), ['role' => 'interviewer'])->assertRedirect();
        $this->assertSame('interviewer', $user->fresh()->role);

        $this->actingAs($admin)->patch(route('admin.users.role', $admin), ['role' => 'hrd']);
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_non_admin_cannot_open_user_management(): void
    {
        $hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $this->actingAs($hrd)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($hrd)->patch(route('admin.users.role', $hrd), ['role' => 'admin'])->assertForbidden();
    }

    public function test_session_list_filters_by_candidate_name_and_opening(): void
    {
        $hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $opening = $this->openingWithCriteria($hrd);
        $other = $this->openingWithCriteria($hrd);
        $s1 = $this->sessionFor($opening, $hrd);
        $s2 = $this->sessionFor($other, $hrd);
        $s1->candidate->update(['name' => 'Rina Unik']);

        $this->actingAs($hrd)->get(route('interview-sessions.index', ['q' => 'Unik']))
            ->assertOk()->assertSee('Rina Unik')->assertDontSee($s2->candidate->name);

        $this->actingAs($hrd)->get(route('interview-sessions.index', ['job_opening_id' => $other->id]))
            ->assertOk()->assertSee($s2->candidate->name)->assertDontSee('Rina Unik');
    }

    public function test_manual_scoring_is_blind_until_submitted(): void
    {
        $hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $rater = User::factory()->create(['role' => User::ROLE_INTERVIEWER]);
        $opening = $this->openingWithCriteria($hrd);
        $session = $this->sessionFor($opening, $hrd, [$rater]);
        [$teknis, $komunikasi] = $opening->criteria;

        // HRD submits first.
        $this->actingAs($hrd)->post(route('manual-assessments.save', $session), [
            'scores' => [$teknis->id => 4, $komunikasi->id => 2],
            'submit' => 1,
        ])->assertRedirect();

        // Rater saves a draft: must not see HRD's scores yet.
        $this->actingAs($rater)->post(route('manual-assessments.save', $session), [
            'scores' => [$teknis->id => 5, $komunikasi->id => 5],
            'submit' => 0,
        ])->assertRedirect();
        $this->actingAs($rater)->get(route('interview-sessions.show', $session))
            ->assertOk()->assertDontSee('Penilaian panel lain');

        // After submitting, the rater sees the comparison table.
        $this->actingAs($rater)->post(route('manual-assessments.save', $session), [
            'scores' => [$teknis->id => 5, $komunikasi->id => 3],
            'submit' => 1,
        ]);
        $this->actingAs($rater)->get(route('interview-sessions.show', $session))
            ->assertOk()->assertSee('Penilaian panel lain')->assertSee($hrd->name);

        // Submitted assessments are locked.
        $this->actingAs($rater)->post(route('manual-assessments.save', $session), [
            'scores' => [$teknis->id => 1, $komunikasi->id => 1],
            'submit' => 1,
        ]);
        $mine = ManualAssessment::where('rater_id', $rater->id)->firstOrFail();
        $this->assertSame(5.0, $mine->scores->firstWhere('criterion_id', $teknis->id)->score);
        // Weighted: (5*3 + 3*1) / 4 = 4.5
        $this->assertSame(4.5, $mine->weightedScore());
    }

    public function test_manual_scores_use_the_zero_to_ten_scale_in_half_steps(): void
    {
        $hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $opening = $this->openingWithCriteria($hrd);
        $session = $this->sessionFor($opening, $hrd);
        $ids = $opening->criteria->pluck('id');

        foreach ([7.3, 10.5, -1] as $bad) {
            $this->actingAs($hrd)->post(route('manual-assessments.save', $session), [
                'scores' => $ids->mapWithKeys(fn ($id) => [$id => $bad])->all(), 'submit' => 0,
            ])->assertSessionHasErrors();
        }
        $this->assertSame(0, ManualAssessment::count());

        $this->actingAs($hrd)->post(route('manual-assessments.save', $session), [
            'scores' => $ids->mapWithKeys(fn ($id) => [$id => 7.5])->all(), 'submit' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertSame(7.5, ManualAssessment::sole()->scores->first()->score);
        $this->actingAs($hrd)->get(route('interview-sessions.show', $session))->assertSee('Patokan skor 0–10');
    }

    public function test_manual_scoring_requires_every_criterion(): void
    {
        $hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $opening = $this->openingWithCriteria($hrd);
        $session = $this->sessionFor($opening, $hrd);

        $this->actingAs($hrd)->post(route('manual-assessments.save', $session), [
            'scores' => [$opening->criteria->first()->id => 4],
        ]);

        $this->assertSame(0, ManualAssessment::count());
    }

    public function test_outsider_cannot_score_a_session(): void
    {
        $hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $outsider = User::factory()->create(['role' => User::ROLE_HRD]);
        $opening = $this->openingWithCriteria($hrd);
        $session = $this->sessionFor($opening, $hrd);

        $this->actingAs($outsider)->post(route('manual-assessments.save', $session), [
            'scores' => $opening->criteria->mapWithKeys(fn ($c) => [$c->id => 3])->all(),
            'submit' => 1,
        ])->assertForbidden();
    }

    public function test_comparison_page_ranks_by_weighted_score_and_flags_salary(): void
    {
        $hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $opening = $this->openingWithCriteria($hrd);
        [$teknis, $komunikasi] = $opening->criteria;

        $low = $this->sessionFor($opening, $hrd);
        $high = $this->sessionFor($opening, $hrd);
        $low->candidate->update(['name' => 'Kandidat Rendah', 'expected_salary' => 9_000_000]);
        $high->candidate->update(['name' => 'Kandidat Tinggi', 'expected_salary' => 6_000_000]);

        foreach ([[$low, 2, 5], [$high, 5, 2]] as [$session, $t, $k]) {
            $this->actingAs($hrd)->post(route('manual-assessments.save', $session), [
                'scores' => [$teknis->id => $t, $komunikasi->id => $k],
                'submit' => 1,
            ]);
        }

        $response = $this->actingAs($hrd)->get(route('job-openings.compare', $opening))->assertOk();
        $response->assertSeeInOrder(['Kandidat Tinggi', 'Kandidat Rendah']);
        $response->assertSee('di atas anggaran');
        // Kandidat Tinggi: (5*3 + 2*1)/4 = 4.25
        $response->assertSee('4,25');
    }
}
