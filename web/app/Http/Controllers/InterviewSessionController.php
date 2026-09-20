<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInterviewSessionRequest;
use App\Models\Candidate;
use App\Models\InterviewSession;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 0: list sessions and create drafts. Recording, transcript and
 * scorecard pages arrive in phases 3-4.
 */
class InterviewSessionController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', InterviewSession::class);

        $user = $request->user();

        $sessions = InterviewSession::query()
            ->when(! $user->isAdmin(), function ($q) use ($user) {
                $q->where(function ($q) use ($user) {
                    $q->where('interviewer_id', $user->id)
                        ->orWhereHas('panel', fn ($p) => $p->whereKey($user->id))
                        ->orWhereHas('candidate.jobOpening', fn ($o) => $o->where('created_by', $user->id));
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with(['candidate.jobOpening', 'interviewer', 'analysisResult'])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('interview-sessions.index', [
            'sessions' => $sessions,
            'filters' => $request->only('status'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', InterviewSession::class);

        $user = $request->user();

        $candidates = Candidate::query()
            ->whereIn('job_opening_id', JobOpening::visibleTo($user)->select('id'))
            ->with('jobOpening:id,title')
            ->orderBy('name')
            ->get();

        return view('interview-sessions.create', [
            'candidates' => $candidates,
            'selectedCandidateId' => $request->integer('candidate_id') ?: null,
            'interviewers' => User::whereIn('role', [User::ROLE_HRD, User::ROLE_INTERVIEWER, User::ROLE_ADMIN])->orderBy('name')->get(),
            'defaultInterviewerId' => $user->id,
        ]);
    }

    public function store(StoreInterviewSessionRequest $request): RedirectResponse
    {
        $this->authorize('create', InterviewSession::class);

        $candidate = Candidate::findOrFail($request->integer('candidate_id'));
        $this->authorize('update', $candidate->jobOpening);

        $session = InterviewSession::create([
            'candidate_id' => $candidate->id,
            'interviewer_id' => $request->integer('interviewer_id'),
            'notes' => $request->input('notes'),
            'status' => 'draft',
        ]);

        // Lead interviewer is always on the panel.
        $panel = collect($request->input('panel', []))
            ->map(fn ($id) => (int) $id)
            ->push($session->interviewer_id)
            ->unique()
            ->all();
        $session->panel()->sync($panel);

        if ($candidate->status === 'applied') {
            $candidate->update(['status' => 'interviewing']);
        }

        return redirect()
            ->route('interview-sessions.show', $session)
            ->with('status', 'Sesi wawancara dibuat sebagai draf.');
    }

    public function show(InterviewSession $interviewSession): View
    {
        $this->authorize('view', $interviewSession);

        $interviewSession->load([
            'candidate.jobOpening.criteria.questions',
            'interviewer',
            'panel',
            'consents',
            'answers.question',
            'analysisResult',
        ]);

        return view('interview-sessions.show', ['session' => $interviewSession]);
    }

    public function destroy(InterviewSession $interviewSession): RedirectResponse
    {
        $this->authorize('delete', $interviewSession);

        if ($interviewSession->status !== 'draft') {
            return back()->with('status', 'Hanya sesi berstatus draf yang bisa dihapus.');
        }

        $interviewSession->delete();

        return redirect()
            ->route('interview-sessions.index')
            ->with('status', 'Sesi draf dihapus.');
    }
}
