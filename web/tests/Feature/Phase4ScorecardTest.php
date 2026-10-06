<?php

namespace Tests\Feature;

use App\Jobs\GenerateScorecard;
use App\Models\Answer;
use App\Models\Candidate;
use App\Models\InterviewSession;
use App\Models\JobOpening;
use App\Models\User;
use App\Services\Scorecard\ScorecardGenerator;
use App\Services\Scorecard\ScorecardInvalid;
use App\Services\Scorecard\ScorecardNotAllowed;
use App\Services\Scorecard\ScorecardPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The scorecard pipeline with the LLM and the embedding service faked: guards,
 * never trusting the model's JSON, quote verification and re-attribution,
 * saving, the queued button and the blind-rating rule for the AI draft.
 */
class Phase4ScorecardTest extends TestCase
{
    use RefreshDatabase;

    private User $hrd;
    private InterviewSession $session;
    private array $c = [];
    private array $a = [];
    private string $llmContent = '';
    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.llm' => array_merge(config('services.llm'), [
            'provider' => 'gemini', 'api_key' => 'k', 'model' => 'gemini-3.5-flash', 'retries' => 0, 'test_data_only' => true,
        ])]);

        $this->hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $opening = JobOpening::factory()->for($this->hrd, 'creator')->create(['title' => 'Backend Developer (tes)']);
        $tech = $opening->criteria()->create(['name' => 'Pengalaman teknis', 'weight' => 3, 'description' => 'backend dan database']);
        $team = $opening->criteria()->create(['name' => 'Kerja tim', 'weight' => 1]);
        $comm = $opening->criteria()->create(['name' => 'Komunikasi', 'weight' => 1]);
        $this->c = ['tech' => $tech->id, 'team' => $team->id, 'comm' => $comm->id];
        $q1 = $tech->questions()->create(['text' => 'Bagaimana menangani query lambat?', 'position' => 1]);
        $q2 = $team->questions()->create(['text' => 'Bagaimana menanggapi code review?', 'position' => 1]);

        $candidate = Candidate::factory()->for($opening)->create();
        $this->session = InterviewSession::create(['candidate_id' => $candidate->id, 'interviewer_id' => $this->hrd->id,
            'status' => 'ready_for_review', 'is_test_data' => true]);
        $this->session->panel()->sync([$this->hrd->id]);

        $this->a['q1'] = $this->answer($q1->id, [['Saya cek slow query log dulu.', 1000, 3000], ['Lalu saya menabakan index yang tepat.', 3100, 6000]]);
        $this->a['q2'] = $this->answer($q2->id, [['Kalau ditolak saya tanya alasannya ke reviewer.', 500, 4000]]);
    }

    private function answer(int $questionId, array $segments): int
    {
        $answer = Answer::create(['interview_session_id' => $this->session->id, 'question_id' => $questionId,
            'transcript_raw' => implode(' ', array_column($segments, 0))]);
        foreach ($segments as [$text, $start, $end]) {
            $answer->transcriptSegments()->create(['interview_session_id' => $this->session->id, 'speaker' => 'candidate',
                'text' => $text, 'start_ms' => $start, 'end_ms' => $end, 'is_final' => true]);
        }

        return $answer->id;
    }

    /**
     * LLM replies with $json (or raw text); the embedding service gives texts mentioning "microservices" one
     * axis and everything else another. Registered once: Http::fake keeps the first matching stub, so later
     * calls only swap the reply.
     */
    private function fakeServices(array|string $json): void
    {
        $this->llmContent = is_string($json) ? $json : json_encode($json);
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake([
            '*/chat/completions' => fn () => Http::response(['model' => 'gemini-3.5-flash', 'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 400],
                'choices' => [['message' => ['content' => $this->llmContent], 'finish_reason' => 'stop']]]),
            '*/nlp/embed' => fn (Request $r) => Http::response(['model' => 'fake', 'dim' => 2, 'vectors' => array_map(
                fn ($t) => str_contains(mb_strtolower($t), 'microservices') ? [1.0, 0.0] : [0.0, 1.0], $r['texts'])]),
        ]);
    }

    private function reply(array $overrides = []): array
    {
        return array_merge(['overall_summary' => 'Kandidat menjelaskan langkah diagnosis query dan sikap terhadap code review.', 'criteria' => [
            ['criterion_id' => $this->c['tech'], 'has_evidence' => true, 'score' => 7.5, 'rationale' => 'Langkah jelas.',
                'strengths' => ['Mengukur sebelum mengoptimasi'], 'weaknesses' => [], 'evidence' => [
                    ['answer_id' => $this->a['q1'], 'quote' => 'Saya cek slow query log dulu.', 'supports' => 'strength'],
                    // The model "fixed" the ASR error: fuzzy, not verified.
                    ['answer_id' => $this->a['q1'], 'quote' => 'Lalu saya menambahkan index yang tepat', 'supports' => 'strength'],
                    // Right words, wrong answer_id: must be moved to the answer it came from.
                    ['answer_id' => $this->a['q1'], 'quote' => 'Saya tanya alasannya ke reviewer', 'supports' => 'strength'],
                    // Invented.
                    ['answer_id' => $this->a['q1'], 'quote' => 'Saya memimpin migrasi ke microservices', 'supports' => 'strength'],
                ]],
            ['criterion_id' => $this->c['team'], 'has_evidence' => true, 'score' => 12, 'rationale' => 'Skor di luar skala.',
                'strengths' => [], 'weaknesses' => [], 'evidence' => []],
            ['criterion_id' => 999, 'has_evidence' => true, 'score' => 3, 'rationale' => 'Kriteria karangan.',
                'strengths' => [], 'weaknesses' => [], 'evidence' => []],
            // Komunikasi is missing on purpose.
        ]], $overrides);
    }

    private function generator(): ScorecardGenerator
    {
        return app(ScorecardGenerator::class);
    }

    public function test_test_data_only_provider_refuses_participant_sessions(): void
    {
        $this->session->update(['is_test_data' => false]);
        $this->assertStringContainsString('hanya boleh dipakai untuk data uji', $this->generator()->refusal($this->session));

        Http::fake();
        $this->expectException(ScorecardNotAllowed::class);
        try {
            $this->generator()->generate($this->session);
        } finally {
            Http::assertNothingSent();   // nothing about a participant ever left the machine
        }
    }

    public function test_paid_provider_still_needs_consent_v2_for_participants(): void
    {
        config(['services.llm.test_data_only' => false]);
        $this->session->update(['is_test_data' => false]);
        $this->session->consents()->create(['candidate_id' => $this->session->candidate_id, 'agreed_at' => now(), 'consent_text_version' => 'v1']);
        $this->assertStringContainsString('persetujuan v2', $this->generator()->refusal($this->session->fresh()));

        $this->session->consents()->create(['candidate_id' => $this->session->candidate_id, 'agreed_at' => now(), 'consent_text_version' => 'v2']);
        $this->assertNull($this->generator()->refusal($this->session->fresh()));
    }

    public function test_prompt_carries_the_shared_scale_answer_ids_and_the_copy_verbatim_rule(): void
    {
        $messages = (new ScorecardPrompt)->messages($this->session);

        $this->assertStringContainsString('7,5 = Contoh konkret dengan langkah dan alasan yang jelas.', $messages[0]['content']);
        $this->assertStringContainsString('kelipatan 0,5', $messages[0]['content']);
        $this->assertStringContainsString('termasuk salah ketik dan salah dengarnya', $messages[0]['content']);
        $this->assertStringContainsString("[answer_id {$this->a['q1']}]", $messages[1]['content']);
        $this->assertStringContainsString("criterion_id {$this->c['comm']}: Komunikasi", $messages[1]['content']);
    }

    public function test_model_output_is_validated_and_every_quote_verified(): void
    {
        $this->fakeServices($this->reply());

        $result = $this->generator()->generate($this->session);

        $scores = $result->scores()->with('evidenceQuotes')->get()->keyBy('criterion_id');
        $this->assertCount(3, $scores, 'unknown criterion dropped, missing one added');
        $this->assertSame(7.5, $scores[$this->c['tech']]->score);
        $this->assertNull($scores[$this->c['team']]->score, 'score 12 is outside the scale');
        $this->assertNull($scores[$this->c['comm']]->score);
        $this->assertSame('Model tidak menilai kriteria ini.', $scores[$this->c['comm']]->rationale);

        $quotes = $scores[$this->c['tech']]->evidenceQuotes->keyBy('quote_text');
        $exact = $quotes['Saya cek slow query log dulu.'];
        $this->assertSame(['exact', true, 1000, 3000], [$exact->match_method, $exact->is_verified, $exact->start_ms, $exact->end_ms]);
        $fuzzy = $quotes['Lalu saya menambahkan index yang tepat'];
        $this->assertSame(['fuzzy', false], [$fuzzy->match_method, $fuzzy->is_verified]);
        $this->assertSame('lalu saya menabakan index yang tepat', $fuzzy->matched_text);
        $moved = $quotes['Saya tanya alasannya ke reviewer'];
        $this->assertSame([$this->a['q2'], 'normalized'], [$moved->answer_id, $moved->match_method]);
        $this->assertSame('none', $quotes['Saya memimpin migrasi ke microservices']->match_method);

        $v = $result->raw_json['verification'];
        $this->assertSame([1, 1, 1, 1, 1, 1], [$v['exact'], $v['normalized'], $v['fuzzy'], $v['none'], $v['ignored_criteria'], $v['missing_criteria']]);
        $this->assertSame(1, $v['reattributed']);
        $this->assertSame([1200, 400, 'v2', 'full', 'gemini'], [$result->prompt_tokens, $result->completion_tokens, $result->prompt_version, $result->condition, $result->provider]);
        $this->assertSame(7.5, $result->weightedScore(), 'only scored criteria count');
        $this->assertSame('ready_for_review', $this->session->fresh()->status);
    }

    public function test_off_step_scores_snap_to_half_points_and_zero_is_a_real_score(): void
    {
        $this->fakeServices(['overall_summary' => '-', 'criteria' => [
            ['criterion_id' => $this->c['tech'], 'has_evidence' => true, 'score' => 7.3, 'rationale' => '-', 'strengths' => [], 'weaknesses' => [], 'evidence' => []],
            ['criterion_id' => $this->c['team'], 'has_evidence' => true, 'score' => 0, 'rationale' => 'Jawaban keliru.', 'strengths' => [], 'weaknesses' => [], 'evidence' => []],
            ['criterion_id' => $this->c['comm'], 'has_evidence' => false, 'score' => 0, 'rationale' => 'Tidak dijawab.', 'strengths' => [], 'weaknesses' => [], 'evidence' => []],
        ]]);

        $result = $this->generator()->generate($this->session);

        $scores = $result->scores->keyBy('criterion_id');
        $this->assertSame(7.5, $scores[$this->c['tech']]->score);
        $this->assertSame(0.0, $scores[$this->c['team']]->score, 'answered but wrong');
        $this->assertNull($scores[$this->c['comm']]->score, 'no evidence is not zero');
        $this->assertSame(1, $result->raw_json['verification']['snapped_scores']);
    }

    public function test_rerun_replaces_the_draft_and_invalid_json_saves_nothing(): void
    {
        $this->fakeServices($this->reply());
        $this->generator()->generate($this->session);
        $this->fakeServices($this->reply(['criteria' => [['criterion_id' => $this->c['tech'], 'has_evidence' => false, 'score' => 0,
            'rationale' => 'Tidak ada bukti.', 'strengths' => [], 'weaknesses' => [], 'evidence' => []]]]));
        $result = $this->generator()->generate($this->session->fresh());
        $this->assertSame(3, $result->scores()->count());
        $this->assertSame(0, $result->evidenceQuotes()->count(), 'old quotes gone with the old scores');

        $this->fakeServices('Maaf, tidak bisa.');
        $this->expectException(ScorecardInvalid::class);
        try {
            $this->generator()->generate($this->session->fresh());
        } finally {
            $this->assertSame(3, $this->session->fresh()->analysisResult->scores()->count(), 'previous draft untouched');
        }
    }

    public function test_button_queues_the_job_and_refusal_is_explained(): void
    {
        Queue::fake();
        $this->actingAs($this->hrd)->post(route('scorecard.store', $this->session))->assertRedirect();
        Queue::assertPushed(GenerateScorecard::class, fn ($job) => $job->condition === 'full' && $job->statusBefore === 'ready_for_review');
        $this->assertSame('analyzing', $this->session->fresh()->status);

        $this->session->update(['status' => 'ready_for_review', 'is_test_data' => false]);
        $this->actingAs($this->hrd)->post(route('scorecard.store', $this->session))->assertSessionHas('status');
        Queue::assertPushed(GenerateScorecard::class, 1);
    }

    public function test_failed_job_records_the_error_and_restores_the_status(): void
    {
        $this->session->update(['status' => 'analyzing']);
        (new GenerateScorecard($this->session, 'full', 'ready_for_review'))->failed(new \RuntimeException('LLM request failed: 503'));

        $this->assertSame('ready_for_review', $this->session->fresh()->status);
        $this->assertSame('LLM request failed: 503', $this->session->fresh()->analysisResult->error_message);
    }

    public function test_ai_draft_is_hidden_from_a_rater_who_has_not_scored_yet(): void
    {
        $this->fakeServices($this->reply());
        $this->generator()->generate($this->session);
        $summary = 'Kandidat menjelaskan langkah diagnosis query';

        // Test session: visible straight away, with the test-data notices.
        $this->actingAs($this->hrd)->get(route('interview-sessions.show', $this->session))
            ->assertOk()->assertSee($summary)->assertSee('Mode data uji')->assertSee('ejaan berbeda dari transkrip')
            ->assertSee('Patokan skor 0–10')->assertSee('7,5');

        // Participant session: blind until this rater submits.
        $this->session->update(['is_test_data' => false]);
        $this->actingAs($this->hrd)->get(route('interview-sessions.show', $this->session))
            ->assertOk()->assertDontSee($summary)->assertSee('Kirim penilaian Anda di atas dulu');
    }

    public function test_demo_session_is_built_from_the_real_asr_logs(): void
    {
        $this->seed();

        $this->artisan('hirenote:demo-session')->assertSuccessful();
        $this->artisan('hirenote:demo-session')->assertSuccessful();   // re-runnable

        $demo = InterviewSession::where('is_test_data', true)->whereHas('candidate', fn ($q) => $q->where('email', 'data-uji@example.test'))->sole();
        $this->assertSame(4, $demo->answers()->count());
        $this->assertSame('ready_for_review', $demo->status);
        $project = $demo->answers()->whereHas('question', fn ($q) => $q->where('text', 'like', 'Ceritakan proyek backend%'))->sole();
        $this->assertStringContainsString('kweri yang lambat di label transseksi', $project->transcript_raw, 'ASR errors kept as they were');
        $this->assertSame(3, $project->transcriptSegments()->count());
    }
}
