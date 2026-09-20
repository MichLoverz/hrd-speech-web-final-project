<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCriterionRequest;
use App\Models\Criterion;
use App\Models\JobOpening;
use Illuminate\Http\RedirectResponse;

/**
 * Criteria are managed inline from the job opening page, so there is no
 * index/create/show here; only the writes.
 */
class CriterionController extends Controller
{
    public function store(StoreCriterionRequest $request, JobOpening $jobOpening): RedirectResponse
    {
        $this->authorize('update', $jobOpening);

        $criterion = $jobOpening->criteria()->create($request->validated());

        return back()->with('status', "Kriteria \"{$criterion->name}\" ditambahkan.");
    }

    public function update(StoreCriterionRequest $request, JobOpening $jobOpening, Criterion $criterion): RedirectResponse
    {
        $this->authorize('update', $jobOpening);
        abort_unless($criterion->job_opening_id === $jobOpening->id, 404);

        $criterion->update($request->validated());

        return back()->with('status', "Kriteria \"{$criterion->name}\" diperbarui.");
    }

    public function destroy(JobOpening $jobOpening, Criterion $criterion): RedirectResponse
    {
        $this->authorize('update', $jobOpening);
        abort_unless($criterion->job_opening_id === $jobOpening->id, 404);

        $criterion->delete();

        return back()->with('status', "Kriteria \"{$criterion->name}\" dan pertanyaannya dihapus.");
    }
}
