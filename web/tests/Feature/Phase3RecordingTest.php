<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Candidate;
use App\Models\InterviewSession;
use App\Models\JobOpening;
use App\Models\Question;
use App\Models\TranscriptSegment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 3: consent, live answer persistence with latency instrumentation,
 * WAV evidence, offline re-transcription and human correction.
 */
class Phase3RecordingTest extends TestCase
{
    use RefreshDatabase;

    private User $hrd;
    private InterviewSession $session;
    private Question $question;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $opening = JobOpening::factory()->for($this->hrd, 'creator')->create();
        $criterion = $opening->criteria()->create(['name' => 'Teknis', 'weight' => 2]);
        $this->question = $criterion->questions()->create(['text' => 'Ceritakan proyek terakhir Anda.', 'position' => 1]);
        $candidate = Candidate::factory()->for($opening)->create();
        $this->session = InterviewSession::create(['candidate_id' => $candidate->id, 'interviewer_id' => $this->hrd->id]);
        $this->session->panel()->sync([$this->hrd->id]);
    }

    public function test_recording_page_requires_consent_first(): void
    {
        $this->actingAs($this->hrd)
            ->get(route('recording.show', $this->session))
            ->assertRedirect(route('recording.consent', $this->session));

        $this->actingAs($this->hrd)
            ->post(route('recording.consent.store', $this->session), ['agree' => '1'])
            ->assertRedirect(route('recording.show', $this->session));

        $this->session->refresh();
        $this->assertSame('recording', $this->session->status);
        $this->assertNotNull($this->session->started_at);
        $consent = $this->session->consents()->first();
        $this->assertSame('v1', $consent->consent_text_version);
        $this->assertNotNull($consent->ip_address);

        $this->actingAs($this->hrd)
            ->get(route('recording.show', $this->session))
            ->assertOk()
            ->assertSee('Ceritakan proyek terakhir Anda.')
            ->assertSee('127.0.0.1:8765');
    }

    public function test_consent_checkbox_is_mandatory(): void
    {
        $this->actingAs($this->hrd)
            ->from(route('recording.consent', $this->session))
            ->post(route('recording.consent.store', $this->session), [])
            ->assertSessionHasErrors('agree');
        $this->assertSame(0, $this->session->consents()->count());
    }

    public function test_answer_store_persists_transcript_and_segment_timings(): void
    {
        $t0 = 1_800_000_000.000;
        $payload = [
            'transcript_raw' => 'Saya memimpin migrasi database. Selama tiga bulan.',
            'duration_ms' => 9200,
            'model_used' => 'faster-whisper/small/int8',
            'asr_prompt' => 'Ceritakan proyek terakhir Anda.',
            'segments' => [
                ['text' => 'Saya memimpin', 'is_final' => false, 'start_ms' => 0, 'end_ms' => 1400,
                    'chunk_received_at' => $t0 + 1.4, 'inference_started_at' => $t0 + 1.41, 'inference_ended_at' => $t0 + 2.0,
                    'emitted_at' => $t0 + 2.01, 'latency_ms' => 610, 'e2e_latency_ms' => 620, 'audio_duration_ms' => 1400, 'real_time_factor' => 0.42],
                ['text' => 'Saya memimpin migrasi database.', 'is_final' => true, 'start_ms' => 0, 'end_ms' => 4000,
                    'chunk_received_at' => $t0 + 4.0, 'inference_started_at' => $t0 + 4.01, 'inference_ended_at' => $t0 + 5.5,
                    'emitted_at' => $t0 + 5.51, 'latency_ms' => 1510, 'e2e_latency_ms' => 1520, 'audio_duration_ms' => 4000, 'real_time_factor' => 0.37],
                ['text' => 'Selama tiga bulan.', 'is_final' => true, 'start_ms' => 4600, 'end_ms' => 9200,
                    'chunk_received_at' => $t0 + 9.2, 'inference_started_at' => $t0 + 9.21, 'inference_ended_at' => $t0 + 10.7,
                    'emitted_at' => $t0 + 10.71, 'latency_ms' => 1510, 'e2e_latency_ms' => 1530, 'audio_duration_ms' => 4600, 'real_time_factor' => 0.33],
            ],
        ];

        $this->actingAs($this->hrd)
            ->putJson(route('answers.store', [$this->session, $this->question]), $payload)
            ->assertOk()
            ->assertJson(['ok' => true, 'segments' => 3, 'avg_latency_ms' => 1510]);

        $answer = Answer::firstOrFail();
        $this->assertSame('live', $answer->asr_mode);
        $this->assertSame('Saya memimpin migrasi database. Selama tiga bulan.', $answer->transcript);
        $this->assertSame(2, $answer->transcriptSegments()->where('is_final', true)->count());

        $seg = TranscriptSegment::where('is_final', true)->orderBy('start_ms')->first();
        // Epoch seconds with millisecond fraction survive the round trip.
        $this->assertSame('510', $seg->emitted_at->format('v'));
        $this->assertSame(1520, $seg->e2e_latency_ms);

        // Re-recording the same question replaces, never duplicates.
        $payload['segments'] = [$payload['segments'][1]];
        $payload['transcript_raw'] = 'Versi kedua.';
        $this->actingAs($this->hrd)->putJson(route('answers.store', [$this->session, $this->question]), $payload)->assertOk();
        $this->assertSame(1, Answer::count());
        $this->assertSame(1, TranscriptSegment::count());
        $this->assertSame('Versi kedua.', Answer::first()->transcript_raw);
    }

    public function test_question_from_another_opening_is_rejected(): void
    {
        $other = JobOpening::factory()->for($this->hrd, 'creator')->create();
        $foreign = $other->criteria()->create(['name' => 'X', 'weight' => 1])->questions()->create(['text' => 'Q', 'position' => 1]);

        $this->actingAs($this->hrd)
            ->putJson(route('answers.store', [$this->session, $foreign]), ['duration_ms' => 1, 'segments' => []])
            ->assertNotFound();
    }

    public function test_outsider_cannot_write_answers(): void
    {
        $outsider = User::factory()->create(['role' => User::ROLE_INTERVIEWER]);
        $this->actingAs($outsider)
            ->putJson(route('answers.store', [$this->session, $this->question]), ['duration_ms' => 1, 'segments' => []])
            ->assertForbidden();
    }

    public function test_audio_is_stored_privately_and_retranscribe_calls_the_service(): void
    {
        Storage::fake('local');

        $this->actingAs($this->hrd)
            ->post(route('answers.audio', [$this->session, $this->question]), [
                'audio' => UploadedFile::fake()->create('answer.wav', 200, 'audio/wav'),
            ])
            ->assertOk();

        $answer = Answer::firstOrFail();
        Storage::disk('local')->assertExists($answer->audio_path);
        $this->assertStringStartsWith("recordings/{$this->session->id}/", $answer->audio_path);

        Http::fake([
            '*/asr/transcribe' => Http::response([
                'model' => 'faster-whisper/small/int8',
                'duration_ms' => 5000,
                'text' => 'Transkrip dari rekaman.',
                'segments' => [['text' => 'Transkrip dari rekaman.', 'start_ms' => 0, 'end_ms' => 5000, 'audio_duration_ms' => 5000, 'real_time_factor' => 0.3]],
            ]),
        ]);

        $this->actingAs($this->hrd)
            ->post(route('answers.retranscribe', [$this->session, $this->question]))
            ->assertRedirect();

        Http::assertSent(function ($request) {
            $prompt = collect($request->data())->firstWhere('name', 'prompt')['contents'] ?? null;

            return str_contains($request->url(), '/asr/transcribe') && $prompt === 'Ceritakan proyek terakhir Anda.';
        });

        $answer->refresh();
        $this->assertSame('file', $answer->asr_mode);
        $this->assertSame('Transkrip dari rekaman.', $answer->transcript_raw);
        $this->assertNull($answer->avg_latency_ms);
        $this->assertSame(1, $answer->transcriptSegments()->count());
    }

    public function test_retranscribe_reports_service_outage_gracefully(): void
    {
        Storage::fake('local');
        $this->actingAs($this->hrd)->post(route('answers.audio', [$this->session, $this->question]), [
            'audio' => UploadedFile::fake()->create('answer.wav', 10, 'audio/wav'),
        ]);
        Http::fake(['*' => Http::response('down', 503)]);

        $this->actingAs($this->hrd)
            ->post(route('answers.retranscribe', [$this->session, $this->question]))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($msg) => str_contains($msg, 'tidak bisa dihubungi'));
    }

    public function test_human_correction_keeps_raw_transcript(): void
    {
        $answer = Answer::create([
            'interview_session_id' => $this->session->id,
            'question_id' => $this->question->id,
            'transcript_raw' => 'saya malamar untuk persisi backhand developer',
        ]);

        $this->actingAs($this->hrd)
            ->patch(route('answers.transcript', $answer), ['transcript_edited' => 'saya melamar untuk posisi backend developer'])
            ->assertRedirect();

        $answer->refresh();
        $this->assertSame('saya malamar untuk persisi backhand developer', $answer->transcript_raw);
        $this->assertSame('saya melamar untuk posisi backend developer', $answer->transcript);

        // Saving the raw text back unchanged clears the edit flag instead of storing a copy.
        $this->actingAs($this->hrd)->patch(route('answers.transcript', $answer), ['transcript_edited' => $answer->transcript_raw]);
        $this->assertNull($answer->fresh()->transcript_edited);
    }

    public function test_finishing_a_session_marks_it_ready_for_review(): void
    {
        $this->session->update(['status' => 'recording', 'started_at' => now()]);

        $this->actingAs($this->hrd)
            ->post(route('recording.finish', $this->session))
            ->assertRedirect(route('interview-sessions.show', $this->session));

        $this->session->refresh();
        $this->assertSame('ready_for_review', $this->session->status);
        $this->assertNotNull($this->session->ended_at);
    }
}
