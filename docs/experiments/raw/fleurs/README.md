# FLEURS `id_id` — tolok ukur WER publik

Korpus: **FLEURS** (Google), bagian bahasa Indonesia, lisensi CC-BY-4.0. Diunduh sekali ke `services/data/fleurs/` (git-ignored, 452 MB untuk split `test`). Alat: `tools/fleurs_eval.py`.

## Kenapa ini ada

Semua angka WER sebelumnya berasal dari rekaman mahasiswa sendiri dengan referensi yang diketik manual. Angka seperti itu tidak punya pembanding luar — tidak ada orang lain yang punya audio itu. FLEURS dipakai peneliti lain untuk melaporkan hasil ASR bahasa Indonesia, jadi angka di sini bisa diletakkan berdampingan dengan literatur.

**Bukan pengganti rekaman wawancara.** FLEURS adalah orang membaca kalimat yang sudah disiapkan. Angkanya tidak boleh dipakai untuk mewakili performa sistem pada wawancara.

## Hasil (2026-09-23, 200 kalimat, seed 20260923)

200 kalimat yang sama untuk kedua model (seed menentukan kalimat mana), 3.865 kata referensi, 41,2 menit audio. Tanpa `initial_prompt` — tidak ada konteks pertanyaan pada bacaan berita.

| Model | WER | CER | RTF median | S/D/I | Kalimat sempurna |
|---|---|---|---|---|---|
| `base` int8, 12 thread | 0,348 | 0,110 | 0,08 | 1075/117/154 | — |
| `small` int8, 12 thread | **0,159** | **0,046** | 0,19 | 501/40/74 | 27/200 |

Log: `20260923-040951_small-200.json` / `.csv` dan `20260923-041340_base-200.json` / `.csv` (CSV berisi teks referensi dan hipotesis per kalimat).

**Jarak `base` → `small` jauh lebih lebar di sini** (0,348 → 0,159, turun 54%) daripada di rekaman sendiri (0,294 → 0,216, turun 27%). Sebabnya kelihatan di kesalahan: FLEURS penuh nama diri dan istilah asing, dan `base` hancur di situ sementara naskah wawancara kosakatanya terbatas. Ini memperkuat rancangan dua model: `base` hanya untuk teks parsial yang memang akan ditimpa, tidak pernah untuk teks yang disimpan dan dinilai.

## Yang mengejutkan, dan harus masuk laporan apa adanya

Dugaan awal: WER FLEURS akan jauh lebih baik daripada WER rekaman sendiri, karena FLEURS bersih dan dibacakan. Ternyata **selisihnya kecil**: FLEURS 0,159 vs naskah-02 `small`+prompt 0,176.

Penjelasan yang paling masuk akal, dan perlu dikatakan terus terang:

1. **Rekaman "wawancara" kita juga naskah yang dibaca**, bukan bicara spontan. Jadi yang dibandingkan sebenarnya bacaan vs bacaan. Selisih yang sesungguhnya — bacaan vs wawancara spontan dengan jeda, "emm", dan ralat — **belum terukur sama sekali**. Itu baru bisa diukur setelah uji dua manusia.
2. **FLEURS tidak semudah kelihatannya.** Kalimatnya dari Wikipedia: penuh nama asing dan istilah. Tiga kesalahan terburuk semuanya nama diri — *Brzezinski*, *Sachin Tendulkar*, *John F. Kennedy*. Naskah wawancara justru lebih ramah karena kosakatanya terbatas dan bisa dibantu `initial_prompt`.

Kesimpulan yang jujur: **angka 0,159 adalah titik acuan, bukan batas atas performa.** Jangan ditulis sebagai "sistem kami mencapai WER 16%" tanpa menyebut ini bacaan berita dan bukan wawancara.

## Batasan korpus yang ditemukan saat memakainya

- **Split `test` dan `dev` 100% penutur laki-laki** (687 dan 350 rekaman, tidak ada satu pun perempuan). Hanya split `train` yang campur (1.498 perempuan, 1.081 laki-laki). Artinya angka WER di atas **tidak mengatakan apa pun tentang suara perempuan**. Kalau laporan perlu membahas itu, harus ambil dari split `train` dan disebutkan bahwa splitnya berbeda.
- **Tidak ada kolom ID pembicara** di TSV — hanya ID kalimat. Jadi rekaman tidak bisa dikelompokkan per orang. Akibatnya **FLEURS tidak bisa dipakai sebagai set uji diarization berlabel**, berbeda dari perkiraan awal di backlog. Uji diarization dengan dua manusia sungguhan tetap harus dilakukan sendiri.
- 687 rekaman berasal dari 328 kalimat unik, jadi ada kalimat yang dibaca lebih dari satu orang.

## Cara mengulang

```
services\.venv\Scripts\python.exe tools\fleurs_eval.py --download          # sekali saja
services\.venv\Scripts\python.exe tools\fleurs_eval.py --model small --limit 200
services\.venv\Scripts\python.exe tools\fleurs_eval.py --model small --limit 0    # semua 687
```

Seed menentukan kalimat mana yang terambil, jadi `--limit 200 --seed 20260923` selalu memberi 200 kalimat yang sama. Penilaian bersifat tingkat korpus (S+D+I dijumlah lalu dibagi total kata), sama seperti cara angka benchmark dilaporkan — bukan rata-rata WER per kalimat.
