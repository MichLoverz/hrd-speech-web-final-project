<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\InterviewSession;
use App\Models\Question;
use App\Models\TranscriptSegment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Persists what the browser produced during a recording: the transcript
 * (final segments joined), every transcript event with its timing, and the
 * raw WAV. Also the offline fallback (re-transcribe from the WAV) and the
 * human correction of the transcript.
 */
class AnswerController extends Controller
{
    /** Called by the recorder JS right after the ASR service sends "done". */
    public function store(Request $request, InterviewSession $interviewSession, Question $question): JsonResponse
    {
        $this->authorize('update', $interviewSession);
        $this->assertQuestionBelongsToSession($interviewSession, $question);

        $data = $request->validate([
            'transcript_raw' => ['nullable', 'string'],
            'duration_ms' => ['required', 'integer', 'min:0'],
            'model_used' => ['nullable', 'string', 'max:64'],
            'asr_prompt' => ['nullable', 'string'],
            'segments' => ['present', 'array'],
            'segments.*.text' => ['present', 'nullable', 'string'],
            'segments.*.is_final' => ['required', 'boolean'],
            'segments.*.start_ms' => ['required', 'integer', 'min:0'],
            'segments.*.end_ms' => ['required', 'integer', 'min:0'],
            'segments.*.chunk_received_at' => ['nullable', 'numeric'],
            'segments.*.inference_started_at' => ['nullable', 'numeric'],
            'segments.*.inference_ended_at' => ['nullable', 'numeric'],
            'segments.*.emitted_at' => ['nullable', 'numeric'],
            'segments.*.latency_ms' => ['nullable', 'integer', 'min:0'],
            'segments.*.e2e_latency_ms' => ['nullable', 'integer', 'min:0'],
            'segments.*.audio_duration_ms' => ['nullable', 'integer', 'min:0'],
            'segments.*.real_time_factor' => ['nullable', 'numeric', 'min:0'],
            'segments.*.confidence' => ['nullable', 'numeric'],
        ]);

        $answer = DB::transaction(function () use ($interviewSession, $question, $data) {
            $answer = Answer::updateOrCreate(
                ['interview_session_id' => $interviewSession->id, 'question_id' => $question->id],
                [
                    'transcript_raw' => $data['transcript_raw'] ?? null,
                    'duration_ms' => $data['duration_ms'],
                    'model_used' => $data['model_used'] ?? null,
                    'asr_mode' => 'live',
                    'asr_prompt' => $data['asr_prompt'] ?? null,
                    'transcribed_at' => now(),
                ],
            );

            $answer->transcriptSegments()->delete();
            $this->insertSegments($answer, $data['segments']);
            $answer->update(['avg_latency_ms' => $this->averageFinalLatency($answer)]);

            return $answer;
        });

        return response()->json([
            'ok' => true,
            'answer_id' => $answer->id,
            'segments' => $answer->transcriptSegments()->count(),
            'avg_latency_ms' => $answer->avg_latency_ms,
        ]);
    }

    /** WAV evidence, uploaded separately so it never blocks the transcript path. */
    public function audio(Request $request, InterviewSession $interviewSession, Question $question): JsonResponse
    {
        $this->authorize('update', $interviewSession);
        $this->assertQuestionBelongsToSession($interviewSession, $question);

        $request->validate([
            'audio' => ['required', 'file', 'max:51200'], // 50 MB: ~26 min of 16 kHz int16
        ]);

        $answer = Answer::firstOrCreate(
            ['interview_session_id' => $interviewSession->id, 'question_id' => $question->id],
        );

        if ($answer->audio_path) {
            Storage::delete($answer->audio_path);
        }

        $path = $request->file('audio')->storeAs(
            "recordings/{$interviewSession->id}",
            "answer-{$answer->id}.wav",
        );
        $answer->update(['audio_path' => $path]);

        return response()->json(['ok' => true, 'answer_id' => $answer->id, 'audio_path' => $path]);
    }

    /**
     * Offline fallback: send the stored WAV to the ASR service over HTTP.
     * Latency numbers from this path are not real-time and are not stored.
     */
    public function retranscribe(InterviewSession $interviewSession, Question $question): RedirectResponse
    {
        $this->authorize('update', $interviewSession);
        $this->assertQuestionBelongsToSession($interviewSession, $question);

        $answer = Answer::where('interview_session_id', $interviewSession->id)
            ->where('question_id', $question->id)
            ->firstOrFail();

        if (! $answer->audio_path || ! Storage::exists($answer->audio_path)) {
            return back()->with('status', 'Tidak ada rekaman audio untuk jawaban ini, jadi tidak bisa ditranskripsi ulang.');
        }

        $prompt = config('services.asr.use_prompt') ? $question->text : '';

        try {
            $response = Http::timeout(config('services.asr.timeout'))
                ->attach('audio', Storage::get($answer->audio_path), 'answer.wav')
                ->post(config('services.asr.http').'/asr/transcribe', ['language' => 'id', 'prompt' => $prompt])
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('status', 'Layanan transkripsi tidak bisa dihubungi. Pastikan ASR service berjalan, lalu coba lagi.');
        }

        DB::transaction(function () use ($answer, $response, $prompt) {
            $answer->update([
                'transcript_raw' => $response['text'] ?? '',
                'duration_ms' => $response['duration_ms'] ?? $answer->duration_ms,
                'model_used' => $response['model'] ?? null,
                'asr_mode' => 'file',
                'asr_prompt' => $prompt ?: null,
                'transcribed_at' => now(),
                'avg_latency_ms' => null,
            ]);
            $answer->transcriptSegments()->delete();
            $segments = collect($response['segments'] ?? [])->map(fn ($s) => [
                'text' => $s['text'] ?? '',
                'is_final' => true,
                'start_ms' => $s['start_ms'] ?? 0,
                'end_ms' => $s['end_ms'] ?? 0,
                'audio_duration_ms' => $s['audio_duration_ms'] ?? null,
                'real_time_factor' => $s['real_time_factor'] ?? null,
                'inference_started_at' => $s['inference_started_at'] ?? null,
                'inference_ended_at' => $s['inference_ended_at'] ?? null,
            ])->all();
            $this->insertSegments($answer, $segments);
        });

        return back()->with('status', 'Jawaban ditranskripsi ulang dari rekaman.');
    }

    /** HRD corrects the transcript by hand. The raw ASR output is kept for WER analysis. */
    public function updateTranscript(Request $request, Answer $answer): RedirectResponse
    {
        $this->authorize('update', $answer->interviewSession);

        $data = $request->validate(['transcript_edited' => ['nullable', 'string', 'max:20000']]);
        $edited = trim($data['transcript_edited'] ?? '');

        // Storing an identical copy would hide "was it edited?"; keep null when unchanged.
        $answer->update([
            'transcript_edited' => ($edited === '' || $edited === trim((string) $answer->transcript_raw)) ? null : $edited,
        ]);

        return back()->with('status', 'Transkrip diperbarui.');
    }

    // ------------------------------------------------------------------ helpers

    private function assertQuestionBelongsToSession(InterviewSession $session, Question $question): void
    {
        $openingId = $session->candidate->job_opening_id;
        abort_unless($question->criterion->job_opening_id === $openingId, 404);
    }

    /** @param array<int, array<string, mixed>> $segments */
    private function insertSegments(Answer $answer, array $segments): void
    {
        $toTime = fn ($epochSeconds) => $epochSeconds === null
            ? null
            : Carbon::createFromTimestampMs((int) round(((float) $epochSeconds) * 1000))->format('Y-m-d H:i:s.v');

        $rows = [];
        $now = now();
        foreach ($segments as $s) {
            $rows[] = [
                'answer_id' => $answer->id,
                'text' => (string) ($s['text'] ?? ''),
                'start_ms' => (int) $s['start_ms'],
                'end_ms' => (int) $s['end_ms'],
                'is_final' => (bool) $s['is_final'],
                'confidence' => $s['confidence'] ?? null,
                'chunk_received_at' => $toTime($s['chunk_received_at'] ?? null),
                'inference_started_at' => $toTime($s['inference_started_at'] ?? null),
                'inference_ended_at' => $toTime($s['inference_ended_at'] ?? null),
                'emitted_at' => $toTime($s['emitted_at'] ?? null),
                'latency_ms' => $s['latency_ms'] ?? null,
                'e2e_latency_ms' => $s['e2e_latency_ms'] ?? null,
                'audio_duration_ms' => $s['audio_duration_ms'] ?? null,
                'real_time_factor' => $s['real_time_factor'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows) {
            TranscriptSegment::insert($rows);
        }
    }

    private function averageFinalLatency(Answer $answer): ?int
    {
        $avg = $answer->transcriptSegments()->where('is_final', true)->whereNotNull('latency_ms')->avg('latency_ms');

        return $avg === null ? null : (int) round($avg);
    }
}
