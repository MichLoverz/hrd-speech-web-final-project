<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuestionRequest;
use App\Models\Criterion;
use App\Models\Question;
use Illuminate\Http\RedirectResponse;

/** Questions are managed inline under their criterion on the job opening page. */
class QuestionController extends Controller
{
    public function store(StoreQuestionRequest $request, Criterion $criterion): RedirectResponse
    {
        $this->authorize('update', $criterion->jobOpening);

        $data = $request->validated();
        $data['position'] = $data['position'] ?? ($criterion->questions()->max('position') + 1);

        $criterion->questions()->create($data);

        return back()->with('status', 'Pertanyaan ditambahkan.');
    }

    public function update(StoreQuestionRequest $request, Criterion $criterion, Question $question): RedirectResponse
    {
        $this->authorize('update', $criterion->jobOpening);
        abort_unless($question->criterion_id === $criterion->id, 404);

        $question->update($request->validated());

        return back()->with('status', 'Pertanyaan diperbarui.');
    }

    public function destroy(Criterion $criterion, Question $question): RedirectResponse
    {
        $this->authorize('update', $criterion->jobOpening);
        abort_unless($question->criterion_id === $criterion->id, 404);

        if ($question->answers()->exists()) {
            return back()->with('status', 'Pertanyaan ini sudah punya jawaban terekam, jadi tidak bisa dihapus.');
        }

        $question->delete();

        return back()->with('status', 'Pertanyaan dihapus.');
    }
}
