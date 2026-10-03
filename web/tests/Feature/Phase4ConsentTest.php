<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\InterviewSession;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Consent v2: the candidate must have agreed to wording that says the transcript
 * text goes to an external AI service before a scorecard may be generated.
 */
class Phase4ConsentTest extends TestCase
{
    use RefreshDatabase;

    private User $hrd;
    private InterviewSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $opening = JobOpening::factory()->for($this->hrd, 'creator')->create();
        $candidate = Candidate::factory()->for($opening)->create();
        $this->session = InterviewSession::create(['candidate_id' => $candidate->id, 'interviewer_id' => $this->hrd->id]);
        $this->session->panel()->sync([$this->hrd->id]);
    }

    private function consent(string $version): void
    {
        $this->session->consents()->create(['candidate_id' => $this->session->candidate_id, 'agreed_at' => now(), 'consent_text_version' => $version]);
    }

    public function test_new_consent_is_v2_and_covers_external_ai(): void
    {
        $this->actingAs($this->hrd)->get(route('recording.consent', $this->session))
            ->assertOk()
            ->assertSee('dikirim ke layanan AI eksternal')
            ->assertDontSee('teks lama');

        $this->actingAs($this->hrd)->post(route('recording.consent.store', $this->session), ['agree' => '1'])
            ->assertRedirect(route('live.show', $this->session));

        $this->assertTrue($this->session->allowsExternalAi());
    }

    public function test_v1_consent_allows_recording_but_not_external_ai(): void
    {
        $this->consent('v1');

        $this->assertTrue($this->session->hasConsent(), 'recording stays allowed: v1 covered that');
        $this->assertFalse($this->session->allowsExternalAi());
        $this->actingAs($this->hrd)->get(route('recording.consent', $this->session))
            ->assertOk()
            ->assertSee('teks lama');
    }

    public function test_renewing_an_old_consent_adds_v2_without_touching_the_recorded_session(): void
    {
        $this->consent('v1');
        $this->session->update(['status' => 'ready_for_review', 'recording_mode' => 'question', 'started_at' => now()->subDay()]);
        $startedAt = $this->session->fresh()->started_at;

        $this->actingAs($this->hrd)->post(route('recording.consent.store', $this->session), ['agree' => '1', 'mode' => 'interview'])
            ->assertRedirect(route('interview-sessions.show', $this->session));

        $s = $this->session->fresh();
        $this->assertTrue($s->allowsExternalAi());
        $this->assertSame(['v1', 'v2'], $s->consents()->orderBy('id')->pluck('consent_text_version')->all(), 'the old record is kept');
        $this->assertSame('ready_for_review', $s->status);
        $this->assertSame('question', $s->recording_mode);
        $this->assertEquals($startedAt, $s->started_at);
    }
}
