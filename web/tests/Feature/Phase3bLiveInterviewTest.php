<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Candidate;
use App\Models\InterviewSession;
use App\Models\JobOpening;
use App\Models\TranscriptSegment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 3b: continuous interview — incremental segment/audio saving,
 * answers derived per question from candidate segments, human correction.
 */
class Phase3bLiveInterviewTest extends TestCase
{
    use RefreshDatabase;

    private User $hrd;
    private InterviewSession $session;
    private array $q = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $opening = JobOpening::factory()->for($this->hrd, 'creator')->create();
        $c = $opening->criteria()->create(['name' => 'Teknis', 'weight' => 2]);
        $this->q[1] = $c->questions()->create(['text' => 'Ceritakan proyek terakhir Anda.', 'position' => 1])->id;
        $this->q[2] = $c->questions()->create(['text' => 'Bagaimana Anda menangani query lambat?', 'position' => 2])->id;
        $candidate = Candidate::factory()->for($opening)->create();
        $this->session = InterviewSession::create(['candidate_id' => $candidate->id, 'interviewer_id' => $this->hrd->id]);
        $this->session->panel()->sync([$this->hrd->id]);
        $this->session->consents()->create(['candidate_id' => $candidate->id, 'agreed_at' => now(), 'consent_text_version' => 'v1']);
    }

    private function seg(int $seq, string $speaker, ?int $qid, string $text, int $start, int $end, bool $final = true): array
    {
        return ['client_seq' => $seq, 'speaker' => $speaker, 'question_id' => $qid, 'text' => $text,
            'start_ms' => $start, 'end_ms' => $end, 'is_final' => $final, 'latency_ms' => 900, 'e2e_latency_ms' => 905,
            'speaker_similarity' => 0.8];
    }

    public function test_consent_defaults_to_interview_mode(): void
    {
        $this->session->consents()->delete();
        $this->actingAs($this->hrd)
            ->post(route('recording.consent.store', $this->session), ['agree' => '1'])
            ->assertRedirect(route('live.show', $this->session));
        $this->assertSame('interview', $this->session->fresh()->recording_mode);
    }

    public function test_live_page_lists_questions_and_config(): void
    {
        $this->actingAs($this->hrd)->get(route('live.show', $this->session))
            ->assertOk()
            ->assertSee('Ceritakan proyek terakhir Anda.')
            ->assertSee('pertanyaan berikutnya');   // trigger phrase config reaches the page
    }

    public function test_segments_are_upserted_by_client_seq_and_answers_derived_on_finish(): void
    {
        Storage::fake('local');
        $s = $this->session;

        $batch1 = ['segments' => [
            $this->seg(1, 'interviewer', $this->q[1], 'Ceritakan proyek terakhir Anda.', 0, 2500),
            $this->seg(2, 'candidate', $this->q[1], 'Saya memimpin migrasi', 3000, 4200, false),
            $this->seg(3, 'candidate', $this->q[1], 'Saya memimpin migrasi database.', 3000, 6000),
        ]];
        $this->actingAs($this->hrd)->postJson(route('live.segments', $s), $batch1)->assertOk()->assertJson(['saved' => 3]);
        $this->assertSame('recording', $s->fresh()->status);

        // Retry of the same batch (network hiccup) must not duplicate.
        $this->actingAs($this->hrd)->postJson(route('live.segments', $s), $batch1)->assertOk();
        $this->assertSame(3, TranscriptSegment::count());

        $this->actingAs($this->hrd)->postJson(route('live.segments', $s), ['segments' => [
            $this->seg(4, 'candidate', $this->q[1], 'Selama tiga bulan.', 6500, 9000),
            $this->seg(5, 'interviewer', $this->q[2], 'Bagaimana Anda menangani query lambat?', 10000, 12500),
            $this->seg(6, 'candidate', $this->q[2], 'Saya mulai dari mengukur dulu.', 13000, 16000),
            $this->seg(7, 'candidate', null, 'Terima kasih.', 17000, 18000),
        ]])->assertOk();

        // Two PCM chunks appended, then finish wraps them into a WAV.
        $pcm = str_repeat(pack('v', 1234), 16000);   // 1 s of int16
        $this->actingAs($this->hrd)->call('POST', route('live.audio', $s), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $pcm)->assertOk();
        $this->actingAs($this->hrd)->call('POST', route('live.audio', $s), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $pcm)->assertOk();

        $this->actingAs($this->hrd)->postJson(route('live.finish', $s), ['asr_model' => 'faster-whisper/small/int8', 'speaker_model' => '3dspeaker'])
            ->assertOk()->assertJson(['ok' => true, 'answers' => 2, 'unassigned' => 1]);

        $s->refresh();
        $this->assertSame('ready_for_review', $s->status);
        $this->assertSame('recordings/'.$s->id.'/session.wav', $s->audio_path);
        Storage::disk('local')->assertExists($s->audio_path);
        $this->assertSame(44 + 2 * strlen($pcm), Storage::disk('local')->size($s->audio_path));
        $this->assertSame(2000, $s->audio_duration_ms);

        $a1 = Answer::where('question_id', $this->q[1])->firstOrFail();
        $this->assertSame('Saya memimpin migrasi database. Selama tiga bulan.', $a1->transcript_raw);  // finals only, candidate only
        $this->assertSame('interview', $a1->asr_mode);
        $this->assertSame(900, $a1->avg_latency_ms);
        $this->assertSame(4, $a1->transcriptSegments()->count());   // interviewer + partial + 2 finals under q1

        $a2 = Answer::where('question_id', $this->q[2])->firstOrFail();
        $this->assertSame('Saya mulai dari mengukur dulu.', $a2->transcript_raw);
    }

    public function test_human_correction_moves_a_segment_and_rebuilds_answers(): void
    {
        $s = $this->session;
        $this->actingAs($this->hrd)->postJson(route('live.segments', $s), ['segments' => [
            $this->seg(1, 'interviewer', $this->q[1], 'Pertanyaan satu.', 0, 2000),
            $this->seg(2, 'candidate', $this->q[1], 'Jawaban satu.', 2500, 5000),
            $this->seg(3, 'candidate', $this->q[1], 'Jawaban dua yang salah alamat.', 9000, 12000),
            $this->seg(4, 'interviewer', $this->q[1], 'Sebenarnya ini kandidat.', 13000, 15000),
        ]]);
        $this->actingAs($this->hrd)->postJson(route('live.finish', $s))->assertOk();
        $this->assertSame('Jawaban satu. Jawaban dua yang salah alamat.', Answer::where('question_id', $this->q[1])->first()->transcript_raw);
        $this->assertNull(Answer::where('question_id', $this->q[2])->first());

        $seg3 = TranscriptSegment::where('client_seq', 3)->firstOrFail();
        $this->actingAs($this->hrd)->patch(route('segments.update', $seg3), ['question_id' => $this->q[2], 'speaker' => 'candidate'])->assertRedirect();
        $seg4 = TranscriptSegment::where('client_seq', 4)->firstOrFail();
        $this->actingAs($this->hrd)->patch(route('segments.update', $seg4), ['question_id' => $this->q[1], 'speaker' => 'candidate'])->assertRedirect();

        $this->assertSame('Jawaban satu. Sebenarnya ini kandidat.', Answer::where('question_id', $this->q[1])->first()->transcript_raw);
        $this->assertSame('Jawaban dua yang salah alamat.', Answer::where('question_id', $this->q[2])->first()->transcript_raw);
        $this->assertTrue($seg4->fresh()->speaker_edited);
        $this->assertSame('edited', $seg3->fresh()->question_reason);
    }

    public function test_outsider_cannot_push_segments(): void
    {
        $outsider = User::factory()->create(['role' => User::ROLE_INTERVIEWER]);
        $this->actingAs($outsider)->postJson(route('live.segments', $this->session), ['segments' => []])->assertForbidden();
    }
}
