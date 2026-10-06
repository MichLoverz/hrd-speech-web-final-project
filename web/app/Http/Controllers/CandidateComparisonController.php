<?php

namespace App\Http\Controllers;

use App\Models\JobOpening;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Side-by-side view of every candidate of an opening, scored per criterion.
 * Phase 1 uses submitted manual assessments only; AI scores join in phase 4.
 */
class CandidateComparisonController extends Controller
{
    public function __invoke(JobOpening $jobOpening): View
    {
        $this->authorize('view', $jobOpening);

        $jobOpening->load([
            'criteria',
            'candidates.interviewSessions.manualAssessments' => fn ($q) => $q->whereNotNull('submitted_at'),
            'candidates.interviewSessions.manualAssessments.scores',
        ]);

        $rows = $jobOpening->candidates->map(function ($candidate) use ($jobOpening) {
            $assessments = $candidate->interviewSessions->flatMap->manualAssessments;
            $allScores = $assessments->flatMap->scores;

            // Mean score per criterion across all submitted raters and sessions.
            $perCriterion = $jobOpening->criteria->mapWithKeys(function ($criterion) use ($allScores) {
                $scores = $allScores->where('criterion_id', $criterion->id)->pluck('score');

                return [$criterion->id => $scores->isEmpty() ? null : round($scores->avg(), 2)];
            });

            $weighted = $this->weightedTotal($jobOpening->criteria, $perCriterion);

            return [
                'candidate' => $candidate,
                'per_criterion' => $perCriterion,
                'weighted' => $weighted,
                'raters' => $assessments->pluck('rater_id')->unique()->count(),
                'salary_position' => $this->salaryPosition($candidate->expected_salary, $jobOpening->salary_min, $jobOpening->salary_max),
            ];
        })->sortByDesc('weighted')->values();

        return view('job-openings.compare', [
            'opening' => $jobOpening,
            'rows' => $rows,
        ]);
    }

    /** Weighted mean on the 0-10 scale, ignoring criteria that have no score yet. */
    private function weightedTotal(Collection $criteria, Collection $perCriterion): ?float
    {
        $sum = 0;
        $weight = 0;
        foreach ($criteria as $criterion) {
            $score = $perCriterion[$criterion->id];
            if ($score === null) {
                continue;
            }
            $sum += $score * $criterion->weight;
            $weight += $criterion->weight;
        }

        return $weight === 0 ? null : round($sum / $weight, 2);
    }

    /** Where the candidate's expectation sits relative to the budget. Never a judgement, only a position. */
    private function salaryPosition(?int $expected, ?int $min, ?int $max): ?string
    {
        if ($expected === null || ($min === null && $max === null)) {
            return null;
        }
        if ($min !== null && $expected < $min) {
            return 'below_range';
        }
        if ($max !== null && $expected > $max) {
            return 'above_range';
        }

        return 'within_range';
    }
}
