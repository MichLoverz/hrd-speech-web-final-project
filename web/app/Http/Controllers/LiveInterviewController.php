<?php

namespace App\Http\Controllers;

use App\Models\InterviewSession;
use App\Models\TranscriptSegment;
use App\Services\AnswerBuilder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Continuous-interview mode (ADR-007): one stream for the whole session.
 * The browser saves as it goes — segments every couple of seconds, raw PCM
 * every few seconds — so a crash loses at most the tail. finish() wraps the
 * PCM into a WAV and derives per-question answers from candidate segments.
 */
class LiveInterviewController extends Controller
{
    public function show(InterviewSession $interviewSession): View|RedirectResponse
    {
        $this->authorize('update', $interviewSession);

        if (! $interviewSession->hasConsent()) {
            return redirect()->route('recording.consent', $interviewSession);
        }

        $interviewSession->load('candidate.jobOpening.criteria.questions');
        if ($interviewSession->recording_mode !== 'interview') {
            $interviewSession->update(['recording_mode' => 'interview']);
        }

        $questions = $interviewSession->candidate->jobOpening->criteria
            ->flatMap(fn ($c) => $c->questions->map(fn ($q) => ['id' => $q->id, 'text' => $q->text, 'criterion' => $c->name]))
            ->values();

        $lastSeq = (int) $interviewSession->transcriptSegments()->max('client_seq');

        return view('recording.live', [
            'session' => $interviewSession,
            'questions' => $questions,
            'lastSeq' => $lastSeq,
            'asr' => [
                'ws' => config('services.asr.ws'),
                'http' => config('services.asr.http'),
                'use_prompt' => config('services.asr.use_prompt'),
                'language' => 'id',
                'trigger_phrases' => config('services.asr.trigger_phrases'),
            ],
        ]);
    }

    /** Batch of transcript events from the browser. Upsert by client_seq so retries are harmless. */
    public function segments(Request $request, InterviewSession $interviewSession): JsonResponse
    {
        $this->authorize('update', $interviewSession);

        $data = $request->validate([
            'segments' => ['present', 'array', 'max:500'],
            'segments.*.client_seq' => ['required', 'integer', 'min:1'],
            'segments.*.text' => ['present', 'nullable', 'string'],
            'segments.*.is_final' => ['required', 'boolean'],
            'segments.*.speaker' => ['nullable', 'string', 'max:16'],
            'segments.*.speaker_similarity' => ['nullable', 'numeric'],
            'segments.*.question_id' => ['nullable', 'integer'],
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

        $validQuestionIds = $interviewSession->candidate->jobOpening->questions()->pluck('questions.id')->all();
        $toTime = fn ($epoch) => $epoch === null ? null
            : Carbon::createFromTimestampMs((int) round(((float) $epoch) * 1000))->format('Y-m-d H:i:s.v');

        $saved = 0;
        foreach ($data['segments'] as $s) {
            $qid = $s['question_id'] ?? null;
            TranscriptSegment::updateOrCreate(
                ['interview_session_id' => $interviewSession->id, 'client_seq' => $s['client_seq']],
                [
                    'text' => (string) ($s['text'] ?? ''),
                    'is_final' => (bool) $s['is_final'],
                    'speaker' => $s['speaker'] ?? null,
                    'speaker_similarity' => $s['speaker_similarity'] ?? null,
                    'question_id' => in_array($qid, $validQuestionIds, true) ? $qid : null,
                    'start_ms' => (int) $s['start_ms'],
                    'end_ms' => (int) $s['end_ms'],
                    'confidence' => $s['confidence'] ?? null,
                    'chunk_received_at' => $toTime($s['chunk_received_at'] ?? null),
                    'inference_started_at' => $toTime($s['inference_started_at'] ?? null),
                    'inference_ended_at' => $toTime($s['inference_ended_at'] ?? null),
                    'emitted_at' => $toTime($s['emitted_at'] ?? null),
                    'latency_ms' => $s['latency_ms'] ?? null,
                    'e2e_latency_ms' => $s['e2e_latency_ms'] ?? null,
                    'audio_duration_ms' => $s['audio_duration_ms'] ?? null,
                    'real_time_factor' => $s['real_time_factor'] ?? null,
                ],
            );
            $saved++;
        }

        if ($interviewSession->status === 'draft') {
            $interviewSession->update(['status' => 'recording', 'started_at' => now()]);
        }

        return response()->json(['ok' => true, 'saved' => $saved]);
    }

    /** Raw Int16 PCM bytes appended to the session's .pcm file (wrapped into a WAV at finish). */
    public function audio(Request $request, InterviewSession $interviewSession): JsonResponse
    {
        $this->authorize('update', $interviewSession);

        $bytes = $request->getContent();
        abort_if($bytes === '' || strlen($bytes) > 20 * 1024 * 1024, 422, 'Empty or oversized audio chunk.');

        $path = $this->pcmPath($interviewSession);
        Storage::makeDirectory(dirname($path));
        $full = Storage::path($path);
        file_put_contents($full, $bytes, FILE_APPEND | LOCK_EX);

        return response()->json(['ok' => true, 'bytes' => filesize($full)]);
    }

    /** End of interview: wrap PCM into a WAV, derive answers, mark ready for review. */
    public function finish(Request $request, InterviewSession $interviewSession, AnswerBuilder $builder): JsonResponse
    {
        $this->authorize('update', $interviewSession);

        $data = $request->validate([
            'asr_model' => ['nullable', 'string', 'max:64'],
            'speaker_model' => ['nullable', 'string', 'max:96'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $pcm = $this->pcmPath($interviewSession);
        $audioPath = null;
        $durationMs = $data['duration_ms'] ?? null;
        if (Storage::exists($pcm)) {
            $audioPath = "recordings/{$interviewSession->id}/session.wav";
            $this->wrapPcmAsWav(Storage::path($pcm), Storage::path($audioPath), 16000);
            Storage::delete($pcm);
            $durationMs = $durationMs ?? (int) ((Storage::size($audioPath) - 44) / 2 / 16000 * 1000);
        }

        $interviewSession->update([
            'audio_path' => $audioPath ?? $interviewSession->audio_path,
            'audio_duration_ms' => $durationMs,
            'asr_model' => $data['asr_model'] ?? $interviewSession->asr_model,
            'speaker_model' => $data['speaker_model'] ?? $interviewSession->speaker_model,
            'status' => 'ready_for_review',
            'ended_at' => now(),
        ]);

        $result = $builder->build($interviewSession->fresh());

        return response()->json(['ok' => true, 'redirect' => route('interview-sessions.show', $interviewSession), ...$result]);
    }

    /** Re-derive answers after segments were relabelled or moved by hand. */
    public function rebuild(InterviewSession $interviewSession, AnswerBuilder $builder): RedirectResponse
    {
        $this->authorize('update', $interviewSession);
        $result = $builder->build($interviewSession);

        return back()->with('status', "Jawaban dibangun ulang: {$result['answers']} pertanyaan terisi, {$result['unassigned']} segmen belum punya pertanyaan.");
    }

    /** Human correction of one segment: which question, which speaker. */
    public function updateSegment(Request $request, TranscriptSegment $segment, AnswerBuilder $builder): RedirectResponse
    {
        $session = $segment->interviewSession;
        abort_unless($session, 404);
        $this->authorize('update', $session);

        $data = $request->validate([
            'question_id' => ['nullable', 'integer'],
            'speaker' => ['nullable', 'in:interviewer,candidate,unknown'],
        ]);

        $valid = $session->candidate->jobOpening->questions()->pluck('questions.id')->all();
        $qid = $data['question_id'] ?? null;
        $segment->update([
            'question_id' => in_array($qid, $valid, true) ? $qid : null,
            'question_reason' => 'edited',
            'speaker' => $data['speaker'] ?? $segment->speaker,
            'speaker_edited' => isset($data['speaker']) && $data['speaker'] !== $segment->getOriginal('speaker') ? true : $segment->speaker_edited,
        ]);
        $builder->build($session);

        return back()->with('status', 'Segmen diperbarui dan jawaban dibangun ulang.');
    }

    // ------------------------------------------------------------------ helpers

    private function pcmPath(InterviewSession $session): string
    {
        return "recordings/{$session->id}/session.pcm";
    }

    private function wrapPcmAsWav(string $pcmFile, string $wavFile, int $sampleRate): void
    {
        $dataSize = filesize($pcmFile);
        $header = pack('A4VA4A4VvvVVvvA4V',
            'RIFF', 36 + $dataSize, 'WAVE',
            'fmt ', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16,
            'data', $dataSize,
        );
        $out = fopen($wavFile, 'wb');
        fwrite($out, $header);
        $in = fopen($pcmFile, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }
}
