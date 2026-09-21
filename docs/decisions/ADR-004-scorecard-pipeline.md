# ADR-004: Pipeline kartu skor — retrieval lokal + LLM + verifikasi kutipan

Tanggal: 2026-09-21 · Status: diterima

## Konteks

Dosen Speech Recognition menyatakan fokus proyek boleh bergeser melampaui ASR (misalnya 40:60 SR:NLP). Nilai jual produk ada di kartu skor kandidat, jadi pengolahan transkrip menjadi kartu skor harus dirancang sebagai komponen yang **dievaluasi**, bukan sekadar pemanggilan LLM.

Kendala: tidak ada dataset wawancara berlabel, dan membuat data latih fiktif dilarang. Jadi tidak ada training model; semua komponen bekerja *zero-shot*.

## Opsi yang dipertimbangkan

1. **LLM saja** — kirim seluruh transkrip sesi ke LLM, minta JSON kartu skor.
2. **Klasifikasi IndoBERT yang di-fine-tune** — prediksi skor per kriteria langsung dari teks. Ditolak: butuh data berlabel ratusan sesi; tidak ada.
3. **Hybrid: retrieval embedding lokal + LLM + verifikasi deterministik** — pilih kalimat relevan per kriteria dengan embedding, LLM hanya menerima kandidat bukti, kutipan diverifikasi ke transkrip asli.

## Keputusan

Opsi 3, dengan opsi 1 dipertahankan sebagai **kondisi baseline eksperimen**.

```
transcript (edited ?? raw), hanya segmen final
  → preprocessing: gabung per jawaban, hapus filler ("eee", "hmm")
  → normalisasi untuk analisis: huruf kecil, spasi, angka lisan → digit, kamus campur kode
  → pecah kalimat; embedding tiap kalimat + deskripsi kriteria (model lokal di services/nlp)
  → top-k kalimat per kriteria
  → LLM (SummarizerInterface → OpenRouterSummarizer | OllamaSummarizer), prompt berversi,
    output JSON: score 1–5, strengths[], weaknesses[], evidence[{answer_id, quote}]
  → verifikasi kutipan ke transkrip ASLI: exact substring → verified;
    gagal → cosine similarity ke kalimat asli ≥ ambang → similar (perlu cek HRD); selain itu → rejected
  → salary_fit dihitung di Laravel, bukan oleh LLM
  → simpan analysis_results / scores / evidence_quotes (prompt_version, model_used)
  → HRD tinjau, sunting, setujui
```

Model embedding kandidat: `indobenchmark/indobert-base-p1` atau `paraphrase-multilingual-MiniLM-L12-v2` (lebih kecil, menangani campur kode). Dipilih setelah uji kecil di Fase 4 dan dicatat sebagai sub-eksperimen.

## Evaluasi (pengganti "training")

| Metrik | Mengukur |
|---|---|
| Verified-quote rate | halusinasi LLM |
| Agreement skor LLM vs skor manual HRD (κ / selisih rata-rata per kriteria) | kewajaran penilaian |
| Edit rate sebelum disetujui | kualitas praktis |
| Korelasi WER jawaban ↔ kutipan gagal verifikasi | jembatan SR → NLP (Bab 7) |

Kondisi eksperimen: (A) LLM transkrip penuh, (B) retrieval + LLM, (C) dua model LLM berbeda via OpenRouter. Satu-satunya parameter yang "dikalibrasi" dari data adalah ambang similarity — ini tuning, bukan training, dan ditulis apa adanya.

## Konsekuensi

- **Baseline manusia wajib ada sebelum AI**: form skor manual (Fase 1) menyimpan penilaian per penilai per sesi di `manual_assessments` + `manual_scores`; penilai baru boleh melihat kartu skor AI setelah mengirim penilaian manualnya (mencegah bias).
- Mahasiswa mengumpulkan ±8–10 sesi wawancara simulasi, masing-masing dinilai 2 orang. Rekaman yang sama dipakai untuk WER.
- Folder Python `asr/` diganti `services/` — satu aplikasi FastAPI dengan router `/asr` dan `/nlp/embed`, karena Laravel tidak bisa menjalankan model embedding.
- Normalisasi untuk analisis ≠ normalisasi untuk WER; keduanya didokumentasikan terpisah. Kutipan selalu diverifikasi terhadap transkrip asli, bukan teks yang dinormalisasi.
- Urutan fase tetap: ASR (2–3) sebelum NLP (4), karena evaluasi NLP butuh transkrip nyata.
