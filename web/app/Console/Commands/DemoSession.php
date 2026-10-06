<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\InterviewSession;
use App\Models\JobOpening;
use App\Models\Question;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A finished, clearly labelled TEST session for trying the scorecard.
 *
 * Nothing in it is invented: every answer is the real `small`-model output of
 * the student reading naskah-01 (first mic test, 2026-09-22, with per-segment
 * timing from the latency log) and naskah-02 (second test). Only the split into
 * questions was done by hand, the same way HRD would do it on the session page.
 * It is read speech, not an interview, so it is excluded from every evaluation.
 */
class DemoSession extends Command
{
    protected $signature = 'hirenote:demo-session';

    protected $description = 'Create (or recreate) a finished test session from the real ASR output of the read-aloud scripts';

    private const EMAIL = 'data-uji@example.test';

    private const SEGMENTS_CSV = 'docs/experiments/raw/latency/20260922-002424_uji-pertama.csv';

    private const NASKAH_02 = 'docs/experiments/raw/mic-tests/2026-09-22_uji-kedua_hyp-small-prompt.txt';

    /** Final segments (seq in the latency log) per question, assigned by hand. 2 = greeting, 27 = salary: no question. */
    private const SEQ_BY_QUESTION = [
        'Ceritakan proyek backend terakhir yang Anda kerjakan. Apa peran Anda di dalamnya?' => [7, 10, 13],
        'Ceritakan satu bug sulit yang pernah Anda temukan. Bagaimana Anda menemukannya?' => [16, 20],
        'Bagaimana Anda menanggapi code review yang menolak pekerjaan Anda?' => [22, 24],
    ];

    private const NASKAH_02_QUESTION = 'Bagaimana Anda menangani query database yang lambat?';

    public function handle(): int
    {
        $opening = JobOpening::where('title', 'Backend Developer')->first();
        if (! $opening) {
            $this->error('Lowongan "Backend Developer" tidak ada. Jalankan `php artisan db:seed` dulu.');

            return self::FAILURE;
        }
        $questions = Question::whereIn('criterion_id', $opening->criteria()->pluck('id'))->get()->keyBy('text');
        foreach ([...array_keys(self::SEQ_BY_QUESTION), self::NASKAH_02_QUESTION] as $text) {
            if (! $questions->has($text)) {
                $this->error("Pertanyaan tidak ditemukan di lowongan: {$text}");

                return self::FAILURE;
            }
        }

        $finals = $this->finals(base_path('../'.self::SEGMENTS_CSV));
        $naskah02 = trim((string) @file_get_contents(base_path('../'.self::NASKAH_02)));
        if ($finals === [] || $naskah02 === '') {
            $this->error('Berkas hasil uji ASR tidak ditemukan di docs/experiments/raw/.');

            return self::FAILURE;
        }

        $session = DB::transaction(function () use ($opening, $questions, $finals, $naskah02) {
            Candidate::where('email', self::EMAIL)->get()->each(function ($c) {
                $c->interviewSessions()->each(fn ($s) => $s->delete());
                $c->delete();
            });

            $candidate = Candidate::create([
                'job_opening_id' => $opening->id,
                'name' => 'Data uji — naskah bacaan',
                'email' => self::EMAIL,
                'expected_salary' => 8_000_000,
                'status' => 'interviewing',
            ]);
            $hrd = User::where('role', User::ROLE_HRD)->first();
            $session = InterviewSession::create([
                'candidate_id' => $candidate->id,
                'interviewer_id' => $hrd?->id,
                'status' => 'ready_for_review',
                'is_test_data' => true,
                'recording_mode' => 'question',
                'asr_model' => 'faster-whisper/small/int8',
                'started_at' => now()->subHour(),
                'ended_at' => now()->subMinutes(30),
                'notes' => 'DATA UJI. Jawaban = keluaran ASR asli (small) dari mahasiswa membaca naskah-01 (uji pertama, 2026-09-22) '
                    .'dan naskah-02 (uji kedua). Pembagian per pertanyaan dilakukan manual. Bukan wawancara peserta; '
                    .'dikecualikan dari semua angka evaluasi.',
            ]);
            $session->panel()->sync(User::whereIn('role', [User::ROLE_HRD, User::ROLE_INTERVIEWER])->pluck('id'));

            foreach (self::SEQ_BY_QUESTION as $text => $seqs) {
                $segments = array_values(array_filter($finals, fn ($f) => in_array($f['seq'], $seqs, true)));
                $answer = $session->answers()->create([
                    'question_id' => $questions[$text]->id,
                    'transcript_raw' => implode(' ', array_column($segments, 'text')),
                    'duration_ms' => array_sum(array_map(fn ($s) => $s['end_ms'] - $s['start_ms'], $segments)),
                    'avg_latency_ms' => (int) round(array_sum(array_column($segments, 'latency_ms')) / count($segments)),
                    'model_used' => 'faster-whisper/small/int8',
                    'asr_mode' => 'live',
                    'transcribed_at' => now()->subMinutes(30),
                ]);
                foreach ($segments as $s) {
                    $answer->transcriptSegments()->create([
                        'interview_session_id' => $session->id,
                        'question_id' => $answer->question_id,
                        'speaker' => 'candidate',
                        'text' => $s['text'],
                        'start_ms' => $s['start_ms'],
                        'end_ms' => $s['end_ms'],
                        'is_final' => true,
                        'latency_ms' => $s['latency_ms'],
                        'audio_duration_ms' => $s['audio_duration_ms'],
                        'real_time_factor' => $s['real_time_factor'],
                    ]);
                }
            }

            // naskah-02 only survives as a text file (no per-segment timing), so it gets no segments
            // and its quotes will have no audio position.
            $session->answers()->create([
                'question_id' => $questions[self::NASKAH_02_QUESTION]->id,
                'transcript_raw' => $naskah02,
                'model_used' => 'faster-whisper/small/int8',
                'asr_mode' => 'file',
                'asr_prompt' => self::NASKAH_02_QUESTION,
                'transcribed_at' => now()->subMinutes(30),
            ]);

            return $session;
        });

        $this->info("Sesi uji #{$session->id} dibuat: {$session->answers()->count()} jawaban, ditandai DATA UJI.");
        $this->line('Pertanyaan "Jelaskan apa itu API..." sengaja tanpa jawaban (kriteria Komunikasi tidak punya bukti langsung).');
        $this->line("Buat kartu skornya: php artisan hirenote:scorecard {$session->id}  (atau tombol di halaman sesi)");

        return self::SUCCESS;
    }

    /** @return list<array{seq:int, start_ms:int, end_ms:int, latency_ms:int, audio_duration_ms:int, real_time_factor:float, text:string}> */
    private function finals(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }
        $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $head = array_shift($rows);
        $out = [];
        foreach ($rows as $row) {
            $r = array_combine($head, $row);
            if ($r['is_final'] === '1') {
                $out[] = ['seq' => (int) $r['seq'], 'start_ms' => (int) $r['start_ms'], 'end_ms' => (int) $r['end_ms'],
                    'latency_ms' => (int) $r['latency_ms'], 'audio_duration_ms' => (int) $r['audio_duration_ms'],
                    'real_time_factor' => (float) $r['real_time_factor'], 'text' => trim($r['text'])];
            }
        }

        return $out;
    }
}
