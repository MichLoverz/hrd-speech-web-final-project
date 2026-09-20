<?php

namespace Database\Seeders;

use App\Models\Candidate;
use App\Models\InterviewSession;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Demo data for development and documentation screenshots.
 *
 * Deliberately NOT seeded: transcripts, transcript_segments, analysis_results,
 * scores, evidence_quotes. Those must come from real recordings and real
 * analysis runs; fabricating them would invalidate the evaluation chapters.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $admin = User::create([
            'name' => 'Admin HireNote',
            'email' => 'admin@hirenote.test',
            'password' => 'password',
            'role' => User::ROLE_ADMIN,
        ]);

        $hrd = User::create([
            'name' => 'Dewi Anggraini',
            'email' => 'hrd@hirenote.test',
            'password' => 'password',
            'role' => User::ROLE_HRD,
        ]);

        $interviewer = User::create([
            'name' => 'Bagus Prasetyo',
            'email' => 'interviewer@hirenote.test',
            'password' => 'password',
            'role' => User::ROLE_INTERVIEWER,
        ]);

        $backend = JobOpening::create([
            'title' => 'Backend Developer',
            'department' => 'Engineering',
            'description' => 'Membangun dan merawat layanan API untuk aplikasi internal. Bekerja dengan PHP/Laravel dan PostgreSQL/MySQL.',
            'salary_min' => 7_000_000,
            'salary_max' => 11_000_000,
            'status' => 'open',
            'created_by' => $hrd->id,
        ]);

        $this->seedCriteria($backend, [
            'Pengalaman teknis' => [
                'weight' => 3,
                'description' => 'Kedalaman pengalaman dengan backend, database, dan API.',
                'questions' => [
                    'Ceritakan proyek backend terakhir yang Anda kerjakan. Apa peran Anda di dalamnya?',
                    'Bagaimana Anda menangani query database yang lambat?',
                ],
            ],
            'Pemecahan masalah' => [
                'weight' => 2,
                'description' => 'Cara kandidat mendiagnosis dan menyelesaikan masalah teknis.',
                'questions' => [
                    'Ceritakan satu bug sulit yang pernah Anda temukan. Bagaimana Anda menemukannya?',
                ],
            ],
            'Komunikasi' => [
                'weight' => 1,
                'description' => 'Kejelasan dalam menjelaskan hal teknis ke orang non-teknis.',
                'questions' => [
                    'Jelaskan apa itu API kepada seseorang yang bukan programmer.',
                ],
            ],
            'Kerja tim' => [
                'weight' => 1,
                'description' => 'Pengalaman kolaborasi, code review, dan menerima masukan.',
                'questions' => [
                    'Bagaimana Anda menanggapi code review yang menolak pekerjaan Anda?',
                ],
            ],
        ]);

        $cs = JobOpening::create([
            'title' => 'Customer Support Specialist',
            'department' => 'Operations',
            'description' => 'Menangani pertanyaan dan keluhan pelanggan melalui chat dan telepon, serta mendokumentasikan kasus.',
            'salary_min' => 4_500_000,
            'salary_max' => 6_000_000,
            'status' => 'open',
            'created_by' => $hrd->id,
        ]);

        $this->seedCriteria($cs, [
            'Empati pelanggan' => [
                'weight' => 3,
                'description' => 'Kemampuan memahami dan menenangkan pelanggan yang kesal.',
                'questions' => [
                    'Ceritakan pengalaman menghadapi pelanggan yang marah. Apa yang Anda lakukan?',
                ],
            ],
            'Kejelasan komunikasi' => [
                'weight' => 2,
                'description' => 'Menjelaskan langkah penyelesaian dengan bahasa yang mudah dipahami.',
                'questions' => [
                    'Jelaskan cara mengatur ulang kata sandi kepada pelanggan yang tidak paham teknologi.',
                ],
            ],
            'Ketelitian' => [
                'weight' => 1,
                'description' => 'Kedisiplinan mencatat kasus dan tindak lanjut.',
                'questions' => [
                    'Bagaimana Anda memastikan tidak ada kasus pelanggan yang terlewat?',
                ],
            ],
        ]);

        $candidates = [
            [$backend, 'Rina Sulistyawati', 'rina.s@example.com', '081234567801', 8_000_000, 'interviewing'],
            [$backend, 'Fajar Nugroho', 'fajar.n@example.com', '081234567802', 10_500_000, 'applied'],
            [$backend, 'Yohanes Tan', 'yohanes.t@example.com', '081234567803', 12_000_000, 'applied'],
            [$backend, 'Siti Rahmawati', 'siti.r@example.com', '081234567804', 7_500_000, 'applied'],
            [$cs, 'Putri Maharani', 'putri.m@example.com', '081234567805', 5_000_000, 'interviewing'],
            [$cs, 'Andi Saputra', 'andi.s@example.com', '081234567806', 5_500_000, 'applied'],
            [$cs, 'Kevin Hartono', 'kevin.h@example.com', '081234567807', 4_800_000, 'applied'],
        ];

        foreach ($candidates as [$opening, $name, $email, $phone, $expected, $status]) {
            Candidate::create([
                'job_opening_id' => $opening->id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'expected_salary' => $expected,
                'status' => $status,
            ]);
        }

        // Two draft sessions, ready to be started from the UI. No audio, no transcript yet.
        foreach (Candidate::where('status', 'interviewing')->get() as $candidate) {
            $session = InterviewSession::create([
                'candidate_id' => $candidate->id,
                'interviewer_id' => $hrd->id,
                'status' => 'draft',
            ]);
            $session->panel()->attach([$hrd->id, $interviewer->id]);
        }
    }

    /**
     * @param  array<string, array{weight:int, description:string, questions:string[]}>  $criteria
     */
    private function seedCriteria(JobOpening $opening, array $criteria): void
    {
        foreach ($criteria as $name => $spec) {
            $criterion = $opening->criteria()->create([
                'name' => $name,
                'weight' => $spec['weight'],
                'description' => $spec['description'],
            ]);

            foreach ($spec['questions'] as $index => $text) {
                $criterion->questions()->create([
                    'text' => $text,
                    'position' => $index + 1,
                ]);
            }
        }
    }
}
