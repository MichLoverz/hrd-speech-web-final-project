<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\InterviewSession;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sets up the short live demo for prototype testing (docs/user-testing/skenario-demo.md):
 * one job opening with two questions, one made-up candidate, and a DRAFT test session
 * ready for "Mulai wawancara". Nothing is recorded here; two people read the script
 * live. Re-running wipes the previous demo so every rehearsal starts clean.
 */
class PrototypeDemo extends Command
{
    protected $signature = 'hirenote:prototype-demo
                            {--single : Only the first question, for a very short live demo}';

    protected $description = 'Create (or reset) the two-question demo opening and a draft test session for a live prototype demo';

    public const OPENING = 'Backend Developer Junior (Demo)';

    /** The interviewer reads these word for word: question tracking matches the spoken text against them. */
    public const QUESTIONS = [
        'Pengalaman teknis' => [
            'weight' => 2,
            'description' => 'Pengalaman menyelesaikan masalah teknis backend, terutama database dan performa.',
            'question' => 'Ceritakan satu masalah performa yang pernah Anda selesaikan.',
        ],
        'Kerja tim' => [
            'weight' => 1,
            'description' => 'Cara kandidat menerima masukan dan bekerja sama dengan rekan satu tim.',
            'question' => 'Bagaimana Anda menanggapi masukan dari rekan satu tim?',
        ],
    ];

    public function handle(): int
    {
        $hrd = User::where('role', User::ROLE_HRD)->orderBy('id')->first();
        if (! $hrd) {
            $this->error('Belum ada user HRD. Jalankan `php artisan db:seed` dulu.');

            return self::FAILURE;
        }

        $questions = $this->option('single') ? array_slice(self::QUESTIONS, 0, 1, true) : self::QUESTIONS;

        $session = DB::transaction(function () use ($hrd, $questions) {
            // Sessions first: their answers block deleting questions.
            JobOpening::where('title', self::OPENING)->get()->each(function (JobOpening $opening) {
                $opening->candidates->each(fn ($c) => $c->interviewSessions()->each(fn ($s) => $s->delete()));
                $opening->delete();
            });

            $opening = JobOpening::create([
                'title' => self::OPENING,
                'department' => 'Engineering',
                'description' => 'Lowongan untuk demo prototype HireNote. Membangun dan merawat API dengan Laravel dan MySQL.',
                'salary_min' => 6_000_000,
                'salary_max' => 9_000_000,
                'status' => 'open',
                'created_by' => $hrd->id,
            ]);
            foreach ($questions as $name => $spec) {
                $opening->criteria()->create(['name' => $name, 'weight' => $spec['weight'], 'description' => $spec['description']])
                    ->questions()->create(['text' => $spec['question'], 'position' => 1]);
            }

            $candidate = Candidate::create([
                'job_opening_id' => $opening->id,
                'name' => 'Raka Pratama (demo)',
                'email' => 'demo-prototype@example.test',
                'expected_salary' => 7_500_000,
                'status' => 'interviewing',
            ]);

            $session = InterviewSession::create([
                'candidate_id' => $candidate->id,
                'interviewer_id' => $hrd->id,
                'status' => 'draft',
                'is_test_data' => true,
                'notes' => 'Demo prototype: dua orang membacakan naskah docs/user-testing/skenario-demo.md. Data uji.',
            ]);
            $session->panel()->sync(User::whereIn('role', [User::ROLE_HRD, User::ROLE_INTERVIEWER])->pluck('id'));

            return $session;
        });

        $this->info("Siap: sesi demo #{$session->id} (draf, data uji) untuk \"".self::OPENING.'".');
        $this->line("Buka: http://127.0.0.1:8000/interview-sessions/{$session->id}  (setelah php artisan serve)  →  login sebagai {$hrd->email}  →  Mulai wawancara");

        return self::SUCCESS;
    }
}
