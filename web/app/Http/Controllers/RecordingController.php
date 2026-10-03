<?php

namespace App\Http\Controllers;

use App\Models\Consent;
use App\Models\InterviewSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The live part of an interview: consent -> recording page -> finish.
 * Answers themselves are saved by AnswerController from the browser.
 */
class RecordingController extends Controller
{
    /** Consent screen shown to the candidate before anything is recorded. */
    public function consent(InterviewSession $interviewSession): View
    {
        $this->authorize('update', $interviewSession);

        return view('recording.consent', [
            'session' => $interviewSession->load('candidate'),
            'already' => $interviewSession->allowsExternalAi(),
            // Consented to an older text that does not mention the external AI step: ask again.
            'outdated' => $interviewSession->hasConsent() && ! $interviewSession->allowsExternalAi(),
            'mode' => request()->query('mode') === 'question' ? 'question' : 'interview',
        ]);
    }

    public function storeConsent(Request $request, InterviewSession $interviewSession): RedirectResponse
    {
        $this->authorize('update', $interviewSession);

        $request->validate(['agree' => ['accepted']], ['agree.accepted' => 'Persetujuan harus dicentang untuk melanjutkan.']);

        $reconsent = $interviewSession->hasConsent();

        if (! $interviewSession->allowsExternalAi()) {
            Consent::create([
                'candidate_id' => $interviewSession->candidate_id,
                'interview_session_id' => $interviewSession->id,
                'agreed_at' => now(),
                'ip_address' => $request->ip(),
                'consent_text_version' => Consent::CURRENT_TEXT_VERSION,
            ]);
        }

        // Renewing an old consent must not touch a session that was already recorded.
        if ($reconsent) {
            return redirect()->route('interview-sessions.show', $interviewSession)
                ->with('status', 'Persetujuan versi '.Consent::CURRENT_TEXT_VERSION.' tercatat. Transkrip sesi ini boleh dikirim ke layanan AI untuk kartu skor.');
        }

        $mode = $request->input('mode') === 'question' ? 'question' : 'interview';
        $interviewSession->update(array_filter([
            'recording_mode' => $mode,
            'status' => $interviewSession->status === 'draft' ? 'recording' : null,
            'started_at' => $interviewSession->started_at ?? now(),
        ]));

        return redirect()->route($mode === 'question' ? 'recording.show' : 'live.show', $interviewSession);
    }

    /** The recording page: one question at a time, live transcript. */
    public function show(InterviewSession $interviewSession): View|RedirectResponse
    {
        $this->authorize('update', $interviewSession);

        if (! $interviewSession->hasConsent()) {
            return redirect()->route('recording.consent', $interviewSession);
        }

        $interviewSession->load([
            'candidate.jobOpening.criteria.questions',
            'answers.transcriptSegments' => fn ($q) => $q->where('is_final', true),
        ]);

        $questions = $interviewSession->candidate->jobOpening->criteria
            ->flatMap(fn ($c) => $c->questions->map(fn ($q) => [
                'id' => $q->id,
                'text' => $q->text,
                'criterion' => $c->name,
                'answer' => $interviewSession->answers->firstWhere('question_id', $q->id),
            ]))
            ->values();

        return view('recording.show', [
            'session' => $interviewSession,
            'questions' => $questions,
            'asr' => [
                'ws' => config('services.asr.ws'),
                'http' => config('services.asr.http'),
                'use_prompt' => config('services.asr.use_prompt'),
                'language' => 'id',
            ],
        ]);
    }

    public function finish(InterviewSession $interviewSession): RedirectResponse
    {
        $this->authorize('update', $interviewSession);

        if (in_array($interviewSession->status, ['draft', 'recording'], true)) {
            $interviewSession->update([
                'status' => 'ready_for_review',
                'ended_at' => now(),
            ]);
        }

        return redirect()
            ->route('interview-sessions.show', $interviewSession)
            ->with('status', 'Sesi diakhiri. Transkrip bisa dikoreksi dan dinilai.');
    }
}
