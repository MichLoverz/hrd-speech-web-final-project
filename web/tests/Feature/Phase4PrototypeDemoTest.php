<?php

namespace Tests\Feature;

use App\Console\Commands\PrototypeDemo;
use App\Models\Answer;
use App\Models\Candidate;
use App\Models\InterviewSession;
use App\Models\JobOpening;
use App\Models\User;
use App\Services\Scorecard\ScorecardGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Prototype-testing setup: the test-session checkbox and the two-question demo. */
class Phase4PrototypeDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_can_be_created_as_test_data_and_is_labelled(): void
    {
        $hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $opening = JobOpening::factory()->for($hrd, 'creator')->create();
        $candidate = Candidate::factory()->for($opening)->create();

        $this->actingAs($hrd)->get(route('interview-sessions.create'))->assertOk()->assertSee('Sesi uji / demo');
        $this->actingAs($hrd)->post(route('interview-sessions.store'), [
            'candidate_id' => $candidate->id, 'interviewer_id' => $hrd->id, 'is_test_data' => '1',
        ])->assertRedirect();

        $session = InterviewSession::sole();
        $this->assertTrue($session->is_test_data);
        $this->actingAs($hrd)->get(route('interview-sessions.show', $session))->assertSee('data uji');
    }

    public function test_sessions_are_real_unless_ticked(): void
    {
        $hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $opening = JobOpening::factory()->for($hrd, 'creator')->create();
        $candidate = Candidate::factory()->for($opening)->create();

        $this->actingAs($hrd)->post(route('interview-sessions.store'), ['candidate_id' => $candidate->id, 'interviewer_id' => $hrd->id]);

        $this->assertFalse(InterviewSession::sole()->is_test_data);
    }

    public function test_prototype_demo_builds_a_clean_two_question_draft_test_session(): void
    {
        $this->seed();

        $this->artisan('hirenote:prototype-demo')->assertSuccessful();
        $first = InterviewSession::where('is_test_data', true)->sole();
        Answer::create(['interview_session_id' => $first->id, 'question_id' => $first->candidate->jobOpening->criteria->first()->questions->first()->id,
            'transcript_raw' => 'rehearsal']);   // a rehearsal left something behind
        $this->artisan('hirenote:prototype-demo')->assertSuccessful();

        $opening = JobOpening::where('title', PrototypeDemo::OPENING)->sole();
        $this->assertSame(
            array_column(PrototypeDemo::QUESTIONS, 'question'),
            $opening->criteria()->orderBy('id')->get()->flatMap->questions->pluck('text')->all(),
            'question order = reading order in the script',
        );

        $session = InterviewSession::where('is_test_data', true)->sole();
        $this->assertNotSame($first->id, $session->id, 'previous demo wiped');
        $this->assertSame(['draft', 0], [$session->status, $session->answers()->count()]);
        $this->assertGreaterThanOrEqual(2, $session->panel()->count());

        // Once answers exist, the free test-data-only LLM may process it.
        Answer::create(['interview_session_id' => $session->id, 'question_id' => $opening->criteria->first()->questions->first()->id, 'transcript_raw' => 'jawaban']);
        config(['services.llm.test_data_only' => true]);
        $this->assertNull(app(ScorecardGenerator::class)->refusal($session->fresh()));
    }
}
