<?php

namespace App\Services\Scorecard;

use App\Models\InterviewSession;
use App\Support\ScoreScale;

/**
 * The scorecard prompt (ADR-010). Versioned: any change to the wording, the
 * schema or the rules bumps VERSION, and every analysis_results row records the
 * version it was made with, so experiment rows stay comparable.
 *
 * Two conditions from ADR-004:
 *   full       every answer transcript in the session (A)
 *   retrieval  only the top-k sentences per criterion from EvidenceRetriever (B)
 */
class ScorecardPrompt
{
    public const VERSION = 'v2';   // v2: 0-10 scale in steps of 0.5 instead of 1-5 labels

    public const CONDITIONS = ['full', 'retrieval'];

    /**
     * @param  array<int, list<array{answer_id:int, text:string}>>|null  $evidence  retrieval condition only, keyed by criterion id
     * @return list<array{role: string, content: string}>
     */
    public function messages(InterviewSession $session, string $condition = 'full', ?array $evidence = null): array
    {
        return [
            ['role' => 'system', 'content' => $this->system()],
            ['role' => 'user', 'content' => $this->user($session, $condition, $evidence)],
        ];
    }

    public function system(): string
    {
        $scale = collect(ScoreScale::ANCHORS)->map(fn ($text, $n) => ScoreScale::format((float) $n)." = {$text}")->implode("\n");

        return <<<TXT
Anda membantu HRD menyusun DRAF kartu skor untuk wawancara kerja terstruktur. Draf ini akan diperiksa, disunting, dan disetujui oleh manusia. Anda tidak memutuskan apakah kandidat diterima, dan tidak boleh memberi rekomendasi terima atau tolak.

ATURAN
1. Nilai hanya isi jawaban kandidat di transkrip, dibandingkan dengan deskripsi tiap kriteria. Jangan memakai nama, jenis kelamin, usia, asal daerah, aksen, atau gaya bahasa sebagai dasar penilaian.
2. Transkrip dibuat oleh pengenalan suara otomatis dan bisa berisi salah dengar, misalnya istilah teknis yang tertulis aneh atau angka yang janggal. Jangan menurunkan skor karena kata yang rusak akibat transkripsi. Kalau sebuah bagian terlalu rusak untuk dipahami, katakan itu di rationale, jangan menebak isinya.
3. Setiap kekuatan dan kelemahan harus didukung kutipan di "evidence". Kutipan WAJIB disalin persis dari transkrip, kata demi kata, termasuk salah ketik dan salah dengarnya. Jangan membetulkan, meringkas, menggabungkan, atau menerjemahkan kutipan. Panjang kutipan 5–30 kata. Isi "answer_id" dengan nomor jawaban tempat kutipan itu berada.
4. Kalau tidak ada jawaban yang memberi bukti untuk suatu kriteria, isi "has_evidence" dengan false (nilai "score" akan diabaikan), kosongkan strengths, weaknesses, dan evidence, lalu jelaskan di "rationale". Jangan memberi angka tanpa bukti. "Tidak ada bukti" berbeda dengan skor 0: skor 0 berarti kandidat menjawab tetapi jawabannya keliru.
5. Kriteria boleh dinilai dari jawaban atas pertanyaan mana pun, tidak harus pertanyaan milik kriteria itu.
6. Skor 0–10 dengan kelipatan 0,5 (misalnya 6, 7,5, 8,5), skala yang sama dengan penilai manusia. Titik di bawah ini adalah patokan, bukan kategori; skor di antaranya boleh dipakai:
{$scale}
7. Tulis dalam bahasa Indonesia yang ringkas dan netral. "rationale" 1–3 kalimat. "overall_summary" 2–4 kalimat yang menggambarkan isi jawaban, bukan keputusan rekrutmen.
TXT;
    }

    private function user(InterviewSession $session, string $condition, ?array $evidence): string
    {
        $session->loadMissing('candidate.jobOpening.criteria.questions', 'answers.question');
        $opening = $session->candidate->jobOpening;

        $lines = ["POSISI: {$opening->title}"];
        if ($opening->description) {
            $lines[] = "Deskripsi posisi: {$opening->description}";
        }

        $lines[] = '';
        $lines[] = 'KRITERIA';
        foreach ($opening->criteria as $c) {
            $lines[] = "- criterion_id {$c->id}: {$c->name} (bobot {$c->weight})".($c->description ? " — {$c->description}" : '');
            foreach ($c->questions as $q) {
                $lines[] = "    pertanyaan terkait: {$q->text}";
            }
        }

        $lines[] = '';
        if ($condition === 'retrieval') {
            $lines[] = 'POTONGAN JAWABAN YANG DIPILIH OTOMATIS PER KRITERIA (bukan seluruh transkrip)';
            foreach ($opening->criteria as $c) {
                $lines[] = "criterion_id {$c->id} ({$c->name}):";
                foreach ($evidence[$c->id] ?? [] as $item) {
                    $lines[] = "  [answer_id {$item['answer_id']}] {$item['text']}";
                }
                if (empty($evidence[$c->id])) {
                    $lines[] = '  (tidak ada potongan yang relevan)';
                }
            }
        } else {
            $lines[] = 'TRANSKRIP JAWABAN KANDIDAT';
            foreach ($session->answers->sortBy(fn ($a) => [$a->question->criterion_id, $a->question->position]) as $a) {
                $lines[] = "[answer_id {$a->id}] Pertanyaan: {$a->question->text}";
                $lines[] = 'Jawaban: '.trim((string) $a->transcript);
                $lines[] = '';
            }
        }

        $lines[] = 'Buat kartu skor untuk setiap criterion_id di atas.';

        return implode("\n", $lines);
    }

    /**
     * Structured-output schema. Kept to features every provider supports (no type unions):
     * "no evidence" is expressed with has_evidence=false rather than a nullable number.
     */
    public function schema(): array
    {
        $quote = [
            'type' => 'object',
            'properties' => [
                'answer_id' => ['type' => 'integer'],
                'quote' => ['type' => 'string'],
                'supports' => ['type' => 'string', 'enum' => ['strength', 'weakness']],
            ],
            'required' => ['answer_id', 'quote', 'supports'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'overall_summary' => ['type' => 'string'],
                'criteria' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'criterion_id' => ['type' => 'integer'],
                            'has_evidence' => ['type' => 'boolean'],
                            'score' => ['type' => 'number', 'description' => '0-10, kelipatan 0.5; diabaikan bila has_evidence false'],
                            'rationale' => ['type' => 'string'],
                            'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'weaknesses' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'evidence' => ['type' => 'array', 'items' => $quote],
                        ],
                        'required' => ['criterion_id', 'has_evidence', 'score', 'rationale', 'strengths', 'weaknesses', 'evidence'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['overall_summary', 'criteria'],
            'additionalProperties' => false,
        ];
    }
}
