<?php

namespace App\Http\Controllers;

use App\Models\InterviewSession;
use App\Models\ManualAssessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A rater's own manual scorecard for a session. Saved as draft until submitted;
 * after submission it is locked and the rater may see the AI scorecard (ADR-004).
 */
class ManualAssessmentController extends Controller
{
    public function save(Request $request, InterviewSession $interviewSession): RedirectResponse
    {
        $this->authorize('view', $interviewSession);

        $criteria = $interviewSession->candidate->jobOpening->criteria;

        $data = $request->validate([
            'scores' => ['required', 'array'],
            'scores.*' => ['required', 'numeric', 'min:0', 'max:10', 'multiple_of:0.5'],   // ScoreScale
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:1000'],
            'overall_notes' => ['nullable', 'string', 'max:2000'],
            'submit' => ['nullable', 'boolean'],
        ], [], [
            'scores.*' => 'skor',
            'notes.*' => 'catatan',
        ]);

        // Only criteria that belong to this session's opening are accepted.
        $missing = $criteria->pluck('id')->diff(array_keys($data['scores']));
        if ($missing->isNotEmpty()) {
            return back()->withInput()->with('status', 'Semua kriteria harus diberi skor sebelum disimpan.');
        }

        $assessment = ManualAssessment::firstOrCreate([
            'interview_session_id' => $interviewSession->id,
            'rater_id' => $request->user()->id,
        ]);

        if ($assessment->isSubmitted()) {
            return back()->with('status', 'Penilaian Anda sudah dikirim dan tidak bisa diubah lagi.');
        }

        foreach ($criteria as $criterion) {
            $assessment->scores()->updateOrCreate(
                ['criterion_id' => $criterion->id],
                [
                    'score' => $data['scores'][$criterion->id],
                    'notes' => $data['notes'][$criterion->id] ?? null,
                ],
            );
        }

        $assessment->overall_notes = $data['overall_notes'] ?? null;
        if ($request->boolean('submit')) {
            $assessment->submitted_at = now();
        }
        $assessment->save();

        return redirect()
            ->route('interview-sessions.show', $interviewSession)
            ->with('status', $assessment->isSubmitted() ? 'Penilaian manual dikirim.' : 'Draf penilaian disimpan.');
    }
}
