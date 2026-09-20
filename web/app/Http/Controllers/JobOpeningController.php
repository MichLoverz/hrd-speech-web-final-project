<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJobOpeningRequest;
use App\Models\JobOpening;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class JobOpeningController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', JobOpening::class);

        $openings = JobOpening::query()
            ->visibleTo($request->user())
            ->search($request->string('q'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->withCount(['criteria', 'candidates'])
            ->with('creator')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('job-openings.index', [
            'openings' => $openings,
            'filters' => $request->only('q', 'status'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', JobOpening::class);

        return view('job-openings.create', ['opening' => new JobOpening(['status' => 'draft'])]);
    }

    public function store(StoreJobOpeningRequest $request): RedirectResponse
    {
        $this->authorize('create', JobOpening::class);

        $opening = JobOpening::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('job-openings.show', $opening)
            ->with('status', 'Lowongan disimpan. Tambahkan kriteria dan pertanyaan di bawah.');
    }

    public function show(JobOpening $jobOpening): View
    {
        $this->authorize('view', $jobOpening);

        $jobOpening->load(['creator', 'criteria.questions', 'candidates' => fn ($q) => $q->latest()]);

        return view('job-openings.show', ['opening' => $jobOpening]);
    }

    public function edit(JobOpening $jobOpening): View
    {
        $this->authorize('update', $jobOpening);

        return view('job-openings.edit', ['opening' => $jobOpening]);
    }

    public function update(StoreJobOpeningRequest $request, JobOpening $jobOpening): RedirectResponse
    {
        $this->authorize('update', $jobOpening);

        $jobOpening->update($request->validated());

        return redirect()
            ->route('job-openings.show', $jobOpening)
            ->with('status', 'Perubahan lowongan disimpan.');
    }

    public function destroy(JobOpening $jobOpening): RedirectResponse
    {
        $this->authorize('delete', $jobOpening);

        $jobOpening->delete();

        return redirect()
            ->route('job-openings.index')
            ->with('status', "Lowongan \"{$jobOpening->title}\" dihapus beserta kriteria, pertanyaan, dan kandidatnya.");
    }
}
