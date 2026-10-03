# 2026-10-03 — Uji kewajaran QuoteVerifier dengan embedding sungguhan

**Bukan evaluasi. `for_report = no`.** Kutipan di bawah ditulis tangan oleh AI sebagai alat cek logika, bukan keluaran LLM dan bukan data peserta. Jumlahnya enam, jadi tidak bisa dipakai untuk mengkalibrasi ambang.

- Transkrip: `raw/mic-tests/2026-09-22_uji-kedua_hyp-small-prompt.txt` (keluaran ASR `small`+prompt, nyata)
- Embedding: `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` (fastembed, ONNX terkuantisasi Qdrant), lewat `POST /nlp/embed`
- Ambang: similarity 0,80, fuzzy 0,85 (keduanya sementara)
- Skrip: dijalankan di luar repo dengan `new Answer([...transcript_edited...])` supaya tidak butuh database; timestamp karenanya kosong

## Kutipan uji

| Label | Kutipan |
|---|---|
| verbatim | langkah pertama biasanya menabakan index yang tepat |
| llm-fixed-asr | langkah pertama biasanya menambahkan index yang tepat |
| case/punct | saya cek slow query log, dan jalankan EXPLAIN |
| paraphrase | Kandidat memakai eager loading di Laravel untuk mengatasi masalah N+1 query |
| asr-fixed | optimasi tanpa angka sebelum dan sesudah cuma perasaan |
| invented-offtopic | Saya pernah memimpin tim sepuluh orang untuk migrasi ke microservices |
| invented-ontopic | Saya biasanya menambahkan replika baca untuk membagi beban database |

## Versi 1: tiga tingkat, jendela = kalimat (sebelum perbaikan)

Kutipan "verbatim" versi 1 tanpa sengaja ditulis dari transkrip `medium` ("menambahkan"), padahal transkrip `small` berbunyi "menabakan". Justru itu yang membuka masalah.

```
verbatim           none       score=0.5858   -> Misalnya komposit index di kolom yang dipakai diware dan order by Kalau masalahnya ...
case/punct         normalized score=-
paraphrase         none       score=0.5943   -> Jadi saya pakai eager loading dengan width di Laravel supaya jadi satu query saja ...
asr-fixed          none       score=0.4577
invented-offtopic  none       score=0.1086
invented-ontopic   none       score=0.5118
```

## Versi 2: lima tingkat (fuzzy + jendela kata 8/16/24)

```
verbatim           exact      score=-          1ms -> 
llm-fixed-asr      fuzzy      score=0.9623     2ms -> langkah pertama biasanya menabakan index yang tepat
case/punct         normalized score=-          0ms -> 
paraphrase         none       score=0.7612   889ms -> sering kali itu n plus satu query. Jadi saya pakai eager loading dengan width di Laravel supaya jadi
asr-fixed          none       score=0.6022    16ms -> naptis misasi tanpangkan sebelum datang saja cuma perasaan.
invented-offtopic  none       score=0.178     19ms -> besarnya saya mulai dari mengukur dulu bukan menembak Saya cek slow query log dan jalankan explain
invented-ontopic   none       score=0.6459    18ms -> supaya jadi satu query saja Untuk data yang jarang berubah, saya tambahkan caching di redis dengan
```

## Bacaan

- LLM yang membetulkan salah dengar ASR sekarang tertangkap sebagai `fuzzy` (0,962), bukan dianggap karangan.
- Parafrase sungguhan naik dari 0,594 ke 0,761, tapi tetap di bawah ambang 0,80. Karangan yang sejalan topik mendapat 0,646. Selisihnya tipis (0,12), jadi ambang harus ditentukan dari kutipan LLM nyata.
- "asr-fixed" (0,602) adalah bagian yang rusak parah oleh ASR ("naptis misasi tanpangkan"). Tidak ada tingkat yang bisa menyelamatkannya, dan memang sebaiknya tidak.
