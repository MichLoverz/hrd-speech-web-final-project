<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Candidate;
use App\Models\InterviewSession;
use App\Models\JobOpening;
use App\Models\User;
use App\Services\Nlp\EmbeddingClient;
use App\Services\Nlp\EvidenceRetriever;
use App\Services\Nlp\NlpServiceUnavailable;
use App\Services\Nlp\QuoteMatch;
use App\Services\Nlp\QuoteVerifier;
use App\Services\Nlp\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 4 building blocks that need no LLM: text normalisation, quote
 * verification (hallucination guard) and evidence retrieval. The embedding
 * service is faked with keyword-axis vectors, so these tests check the
 * logic, not the embedding model's quality — that is an experiment.
 */
class Phase4NlpTest extends TestCase
{
    use RefreshDatabase;

    private InterviewSession $session;
    private array $criteria = [];
    private array $questions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $hrd = User::factory()->create(['role' => User::ROLE_HRD]);
        $opening = JobOpening::factory()->for($hrd, 'creator')->create();
        $tech = $opening->criteria()->create(['name' => 'Teknis database', 'description' => 'index query optimasi', 'weight' => 2]);
        $team = $opening->criteria()->create(['name' => 'Kerja sama tim', 'description' => 'tim konflik komunikasi', 'weight' => 1]);
        $this->criteria = [$tech->id, $team->id];
        $this->questions[1] = $tech->questions()->create(['text' => 'Bagaimana menangani query lambat?', 'position' => 1])->id;
        $this->questions[2] = $team->questions()->create(['text' => 'Ceritakan konflik dalam tim.', 'position' => 1])->id;
        $candidate = Candidate::factory()->for($opening)->create();
        $this->session = InterviewSession::create(['candidate_id' => $candidate->id, 'interviewer_id' => $hrd->id]);
    }

    /** Answer whose raw transcript is the join of its candidate segments, as AnswerBuilder makes it. */
    private function answer(int $q, array $segments): Answer
    {
        $answer = Answer::create([
            'interview_session_id' => $this->session->id,
            'question_id' => $this->questions[$q],
            'transcript_raw' => implode(' ', array_column($segments, 0)),
        ]);
        // An interviewer segment under the same question must not shift the timing map.
        $answer->transcriptSegments()->create(['interview_session_id' => $this->session->id, 'speaker' => 'interviewer',
            'text' => 'Silakan dijawab.', 'start_ms' => 0, 'end_ms' => 900, 'is_final' => true]);
        foreach ($segments as [$text, $start, $end]) {
            $answer->transcriptSegments()->create(['interview_session_id' => $this->session->id, 'speaker' => 'candidate',
                'text' => $text, 'start_ms' => $start, 'end_ms' => $end, 'is_final' => true]);
        }

        return $answer;
    }

    /** Fake /nlp/embed: each text becomes a unit vector on the axis of the first keyword it contains. */
    private function fakeEmbeddings(array $axes = ['index', 'tim', 'liburan']): void
    {
        Http::fake(['*/nlp/embed' => function (Request $request) use ($axes) {
            $vectors = array_map(function ($text) use ($axes) {
                $v = array_fill(0, count($axes) + 1, 0.0);
                $hit = null;
                foreach ($axes as $i => $word) {
                    if (str_contains(mb_strtolower($text), $word)) {
                        $hit = $i;
                        break;
                    }
                }
                $v[$hit ?? count($axes)] = 1.0;

                return $v;
            }, $request['texts']);

            return Http::response(['model' => 'fake-axes', 'dim' => count($axes) + 1, 'vectors' => $vectors]);
        }]);
    }

    private function verifier(float $threshold = 0.8): QuoteVerifier
    {
        return new QuoteVerifier(new EmbeddingClient, $threshold);
    }

    public function test_normalizer_drops_case_punctuation_and_fillers_but_not_numbers(): void
    {
        $this->assertSame(['saya', 'pakai', 'querynya', 'lagi'], TextNormalizer::tokens('Eee, saya PAKAI query-nya... hmm lagi!'));
        // Spoken numbers stay words: "delapan juta" must never match "80 juta".
        $this->assertSame(['delapan', 'juta'], TextNormalizer::tokens('delapan juta'));
        $this->assertSame(['Satu dua.', 'Tiga?', 'Empat'], TextNormalizer::sentences("Satu dua. Tiga? Empat"));
        $this->assertCount(3, TextNormalizer::sentences(implode(' ', array_fill(0, 90, 'kata'))));
        $this->assertSame(2, TextNormalizer::find(['a', 'b', 'c', 'd'], ['c', 'd']));
        $this->assertNull(TextNormalizer::find(['a', 'b'], ['b', 'a']));
    }

    public function test_exact_quote_is_verified_with_audio_position_and_no_service_call(): void
    {
        Http::fake();
        $answer = $this->answer(1, [
            ['Waktu itu query laporan lambat sekali.', 1000, 3000],
            ['Saya menambahkan composite index di kolom tanggal.', 3200, 6000],
            ['Hasilnya turun ke dua detik.', 6100, 8000],
        ]);

        $m = $this->verifier()->verify('"Saya menambahkan composite index di kolom tanggal."', $answer);

        $this->assertSame(QuoteMatch::EXACT, $m->method);
        $this->assertTrue($m->isVerified());
        $this->assertSame([3200, 6000], [$m->startMs, $m->endMs]);
        Http::assertNothingSent();
    }

    public function test_quote_differing_only_in_case_punctuation_or_fillers_is_normalized(): void
    {
        Http::fake();
        $answer = $this->answer(1, [
            ['Eee saya menambahkan, eee, composite index.', 1000, 4000],
            ['Lalu query-nya jadi cepat.', 4100, 6000],
        ]);

        $m = $this->verifier()->verify('saya menambahkan composite index lalu querynya jadi cepat', $answer);

        $this->assertSame(QuoteMatch::NORMALIZED, $m->method);
        $this->assertTrue($m->isVerified());
        $this->assertSame([1000, 6000], [$m->startMs, $m->endMs], 'quote spans both segments');
        Http::assertNothingSent();
    }

    public function test_ellipsis_fragments_must_appear_in_order(): void
    {
        $this->fakeEmbeddings();
        $answer = $this->answer(1, [
            ['Query laporan lambat sekali.', 1000, 3000],
            ['Saya cek execution plan dulu.', 3100, 5000],
            ['Lalu saya menambahkan index.', 5100, 7000],
        ]);

        $inOrder = $this->verifier()->verify('Query laporan lambat sekali ... saya menambahkan index', $answer);
        $this->assertSame(QuoteMatch::EXACT, $inOrder->method);
        $this->assertSame([1000, 7000], [$inOrder->startMs, $inOrder->endMs]);

        // Same words, reversed: no longer a quote of what was said, so it falls through to similarity.
        $reversed = $this->verifier()->verify('saya menambahkan index … query laporan lambat sekali', $answer);
        $this->assertNotContains($reversed->method, [QuoteMatch::EXACT, QuoteMatch::NORMALIZED]);
    }

    public function test_asr_error_corrected_by_the_llm_is_fuzzy_not_verified(): void
    {
        Http::fake();
        // Real small-model error from uji kedua: "menambahkan" heard as "menabakan".
        $answer = $this->answer(1, [['Kalau iya, langkah pertama biasanya menabakan index yang tepat.', 1000, 4000]]);

        $m = $this->verifier()->verify('langkah pertama biasanya menambahkan index yang tepat', $answer);

        $this->assertSame(QuoteMatch::FUZZY, $m->method);
        $this->assertFalse($m->isVerified(), 'the LLM changed a word: HRD should see what was transcribed');
        $this->assertSame('langkah pertama biasanya menabakan index yang tepat', $m->matchedText);
        $this->assertSame([1000, 4000], [$m->startMs, $m->endMs]);
        Http::assertNothingSent();
    }

    public function test_changed_number_is_not_forgiven_as_fuzzy(): void
    {
        $this->fakeEmbeddings();
        // Long enough that the changed number alone stays above the fuzzy threshold on characters.
        $answer = $this->answer(1, [['Dengan pengalaman tiga tahun di bidang backend gaji yang saya harapkan sekitar delapan juta per bulan.', 1000, 5000]]);

        $m = $this->verifier()->verify('Dengan pengalaman tiga tahun di bidang backend gaji yang saya harapkan sekitar 80 juta per bulan.', $answer);

        $this->assertNotContains($m->method, [QuoteMatch::EXACT, QuoteMatch::NORMALIZED, QuoteMatch::FUZZY]);
    }

    public function test_paraphrase_is_similar_not_verified_and_points_at_the_source_passage(): void
    {
        $this->fakeEmbeddings();
        $answer = $this->answer(1, [
            ['Awalnya saya bekerja di tim kecil yang terdiri dari empat orang.', 1000, 4000],
            ['Untuk laporan bulanan saya menambahkan composite index di kolom tanggal.', 4100, 8000],
        ]);

        $m = $this->verifier()->verify('Kandidat membuat index gabungan pada kolom tanggal', $answer);

        $this->assertSame(QuoteMatch::SIMILAR, $m->method);
        $this->assertFalse($m->isVerified(), 'a paraphrase needs a human look');
        $this->assertStringContainsString('composite index', $m->matchedText);
        $this->assertSame(8000, $m->endMs);
        Http::assertSent(fn (Request $r) => $r['kind'] === 'passage');
    }

    public function test_invented_quote_is_none_with_its_closest_sentence_kept(): void
    {
        $this->fakeEmbeddings();
        $answer = $this->answer(1, [['Saya menambahkan index.', 1000, 3000]]);

        $m = $this->verifier()->verify('Saya pernah memimpin liburan kantor ke Bali', $answer);

        $this->assertSame(QuoteMatch::NONE, $m->method);
        $this->assertFalse($m->isVerified());
        $this->assertSame(0.0, $m->score);
        $this->assertNull($m->startMs);
    }

    public function test_edited_transcript_is_matched_but_gets_no_timestamps(): void
    {
        Http::fake();
        $answer = $this->answer(1, [['Saya menambah index komposit.', 1000, 3000]]);
        $answer->update(['transcript_edited' => 'Saya menambah composite index.']);

        $m = $this->verifier()->verify('Saya menambah composite index.', $answer);

        $this->assertSame(QuoteMatch::EXACT, $m->method, 'checked against the text the LLM was given');
        $this->assertNull($m->startMs, 'hand-edited text no longer lines up with the audio segments');
    }

    public function test_service_down_raises_instead_of_recording_a_false_none(): void
    {
        Http::fake(fn () => throw new ConnectionException('connection refused'));
        $answer = $this->answer(1, [['Saya menambahkan index.', 1000, 3000]]);

        $this->expectException(NlpServiceUnavailable::class);
        $this->verifier()->verify('Kalimat yang tidak ada di transkrip', $answer);
    }

    public function test_retriever_ranks_sentences_per_criterion_across_all_answers(): void
    {
        $this->fakeEmbeddings();
        $this->answer(1, [
            ['Saya menambahkan index di kolom tanggal.', 1000, 3000],
            ['Ya.', 3100, 3300],                                   // too short to be evidence
            ['Saya juga diskusi dengan tim backend dulu.', 3400, 6000],   // teamwork, said under a technical question
        ]);
        $this->answer(2, [['Konflik di tim saya selesaikan lewat rapat.', 1000, 4000]]);

        $result = (new EvidenceRetriever(new EmbeddingClient))->retrieve($this->session->fresh(), 2);

        [$tech, $team] = $this->criteria;
        $this->assertSame('Saya menambahkan index di kolom tanggal.', $result[$tech][0]['text']);
        $this->assertSame(1.0, $result[$tech][0]['score']);
        $teamTexts = array_column($result[$team], 'text');
        $this->assertContains('Saya juga diskusi dengan tim backend dulu.', $teamTexts);
        $this->assertContains('Konflik di tim saya selesaikan lewat rapat.', $teamTexts);
        $this->assertNotContains('Ya.', array_merge(...array_map(fn ($r) => array_column($r, 'text'), $result)));
        Http::assertSent(fn (Request $r) => $r['kind'] === 'query');
    }
}
