<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCandidateRequest;
use App\Models\Candidate;
use App\Models\JobOpening;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Candidate::class);

        $visibleOpenings = JobOpening::visibleTo($request->user())->orderBy('title')->get(['id', 'title']);

        $candidates = Candidate::query()
            ->whereIn('job_opening_id', $visibleOpenings->pluck('id'))
            ->search($request->string('q'))
            ->when($request->filled('job_opening_id'), fn ($q) => $q->where('job_opening_id', $request->integer('job_opening_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with('jobOpening')
            ->withCount('interviewSessions')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('candidates.index', [
            'candidates' => $candidates,
            'openings' => $visibleOpenings,
            'filters' => $request->only('q', 'job_opening_id', 'status'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Candidate::class);

        return view('candidates.create', [
            'candidate' => new Candidate([
                'status' => 'applied',
                'job_opening_id' => $request->integer('job_opening_id') ?: null,
            ]),
            'openings' => $this->editableOpenings($request),
        ]);
    }

    public function store(StoreCandidateRequest $request): RedirectResponse
    {
        $this->authorize('create', Candidate::class);

        $opening = JobOpening::findOrFail($request->integer('job_opening_id'));
        $this->authorize('update', $opening);

        $data = $request->safe()->except('cv');
        if ($request->hasFile('cv')) {
            $data['cv_path'] = $request->file('cv')->store('cvs');
        }

        $candidate = Candidate::create($data);

        return redirect()
            ->route('candidates.show', $candidate)
            ->with('status', "Kandidat {$candidate->name} disimpan.");
    }

    public function show(Candidate $candidate): View
    {
        $this->authorize('view', $candidate);

        $candidate->load(['jobOpening', 'interviewSessions.interviewer', 'interviewSessions.analysisResult']);

        return view('candidates.show', ['candidate' => $candidate]);
    }

    public function edit(Request $request, Candidate $candidate): View
    {
        $this->authorize('update', $candidate);

        return view('candidates.edit', [
            'candidate' => $candidate,
            'openings' => $this->editableOpenings($request),
        ]);
    }

    public function update(StoreCandidateRequest $request, Candidate $candidate): RedirectResponse
    {
        $this->authorize('update', $candidate);

        $data = $request->safe()->except('cv');
        if ($request->hasFile('cv')) {
            if ($candidate->cv_path) {
                Storage::delete($candidate->cv_path);
            }
            $data['cv_path'] = $request->file('cv')->store('cvs');
        }

        $candidate->update($data);

        return redirect()
            ->route('candidates.show', $candidate)
            ->with('status', 'Data kandidat diperbarui.');
    }

    public function destroy(Candidate $candidate): RedirectResponse
    {
        $this->authorize('delete', $candidate);

        if ($candidate->cv_path) {
            Storage::delete($candidate->cv_path);
        }
        $candidate->delete();

        return redirect()
            ->route('candidates.index')
            ->with('status', "Kandidat {$candidate->name} dihapus.");
    }

    /** CVs live on the private disk; this is the only way to read them. */
    public function cv(Candidate $candidate): StreamedResponse
    {
        $this->authorize('view', $candidate);
        abort_unless($candidate->cv_path && Storage::exists($candidate->cv_path), 404);

        return Storage::download($candidate->cv_path, "CV - {$candidate->name}.pdf");
    }

    private function editableOpenings(Request $request)
    {
        $user = $request->user();

        return JobOpening::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('created_by', $user->id))
            ->orderBy('title')
            ->get(['id', 'title', 'status']);
    }
}
