<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateScorecard;
use App\Models\InterviewSession;
use App\Services\Scorecard\ScorecardGenerator;
use App\Services\Scorecard\ScorecardPrompt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Buat kartu skor": checks the guards now, runs the pipeline on the queue. */
class ScorecardController extends Controller
{
    public function store(Request $request, InterviewSession $interviewSession, ScorecardGenerator $generator): RedirectResponse
    {
        $this->authorize('update', $interviewSession);

        if ($interviewSession->status === 'approved') {
            return back()->with('status', 'Sesi ini sudah disetujui; kartu skornya tidak dibuat ulang.');
        }
        if ($interviewSession->status === 'analyzing') {
            return back()->with('status', 'Kartu skor sedang dibuat. Muat ulang halaman ini sebentar lagi.');
        }
        if ($why = $generator->refusal($interviewSession)) {
            return back()->with('status', $why);
        }

        $condition = in_array($request->input('condition'), ScorecardPrompt::CONDITIONS, true)
            ? $request->input('condition')
            : config('services.llm.scorecard_condition', 'full');

        $before = $interviewSession->status;
        $interviewSession->update(['status' => 'analyzing']);
        GenerateScorecard::dispatch($interviewSession, $condition, $before);

        return back()->with('status', 'Kartu skor sedang dibuat. Pastikan `php artisan queue:work` dan layanan Python berjalan, lalu muat ulang halaman ini.');
    }
}
