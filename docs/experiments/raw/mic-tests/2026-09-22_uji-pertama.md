# Uji mikrofon 1 — 2026-09-22, "uji-pertama"

Uji pertama pipeline ASR dengan suara manusia. Tujuan: memastikan real-time berjalan dan melihat pola kesalahan awal. **Bukan baseline laporan** (naskah dibaca, kondisi ruangan dan mikrofon tidak dikontrol) — tapi disimpan sebagai bukti kondisi awal sistem.

| | |
|---|---|
| Pembicara | Michael (mahasiswa), laki-laki, bahasa Indonesia |
| Materi | `docs/experiments/scripts/naskah-01-backend.md`, dibaca dengan jeda |
| Mikrofon / ruangan | mikrofon laptop bawaan; ruangan tidak dicatat (diisi mahasiswa: ______) |
| Konfigurasi | final `small` int8, partial `base` int8, 12 thread, partial 1200 ms, jeda 500 ms, VAD 0,5, chunk 200 ms |
| Log mentah | `docs/experiments/raw/latency/20260922-002424_uji-pertama.csv` / `.json` |
| Mesin | Ryzen 5 6600H, 16 GB, Windows 11, tanpa GPU |

## Ringkasan latency (dari terminal)

```
partial latency (ms)   n=18   mean=2065.8   median=1472.0   p95=4358.6   min=825.0    max=6323.0
final latency (ms)     n=9    mean=3154.6   median=2496.0   p95=5532.6   min=1879.0   max=6447.0
partial RTF            n=18   mean=0.8      median=0.6      p95=2.3      min=0.1      max=2.4
final RTF              n=9    mean=0.5      median=0.3      p95=1.1      min=0.2      max=1.6
audio final (detik)    55.0      wall_seconds 73.7
input_overflows 0      max_queue_backlog_chunks 32      partials 18  skipped 0  finals 9
```

`max_queue_backlog_chunks = 32` = 6,4 detik audio pernah menunggu di antrian. Ini bukti masalah "inference satu jalur": saat final segmen N berjalan, chunk segmen N+1 menumpuk, dan partial berikutnya keluar terlambat (p95 partial 4,4 s). Ditangani di Fase 3 dengan worker terpisah.

## Transkrip final vs referensi

| # | Referensi | Sistem (`small`) | Latency | WER |
|---|---|---|---|---|
| 1 | Selamat pagi, nama saya Michael, saya melamar untuk posisi Backend Developer. | Selamat pagi, nama saya Michael, saya **Malamar** untuk **Persisi Backhand** Developer | 2390 ms | 0,27 |
| 2 | Di proyek terakhir, saya memimpin migrasi database dari MySQL ke PostgreSQL selama tiga bulan, dengan tim berlima. | di proyek terakhir saya memimpin migrasi database dari MySQL ke PostgreSQL selama **3** bulan dengan **dimatikan orang** | 2496 ms | 0,18 |
| 3a | Tantangan terbesarnya adalah query yang lambat di tabel transaksi, | **Tantang entera besar nya** adalah **kweri** yang lambat di **label transseksi**. | 2633 ms | 0,35 (3a+3b) |
| 3b | jadi saya menambahkan index dan mengubah beberapa query menjadi batch processing. | jadi saya menambahkan index dan mengubah beberapa query menjadi batch processing | 2098 ms | |
| 4 | Bug paling sulit yang pernah saya temukan adalah race condition di payment queue. | **bagi** yang paling sulit yang saya pernah temukan adalah race condition di payment **QE** | 4107 ms | 0,38 |
| 5 | Saya menemukannya dengan menambahkan structured logging, lalu membandingkan timestamp dari dua worker yang berjalan bersamaan. | Saya menemukannya dengan **nambahkan Structure** Logging lalu membandingkan timestamp dari dua worker yang berjalan bersamaan. | 2180 ms | 0,13 |
| 6a | Kalau code review saya ditolak, biasanya saya tanya dulu alasannya, | **kalau 4nya bisa ditabar, bisa bisa ditanyain 2-3nya** | 6447 ms (RTF 1,6) | 0,74 (6a+6b) |
| 6b | karena seringkali reviewer melihat edge case yang saya lewatkan. | karena sering kali **refiur mulia etxkesi** yang saya lewatkan | 4161 ms | |
| 7 | Untuk ekspektasi gaji, saya mengharapkan sekitar delapan juta rupiah, tapi saya terbuka untuk diskusi. | untuk **ekspetasi gadget** sendiri sih saya **mengarahkan** sekitar **80 juta** rupiah tapi saya terbuka untuk **didiskusikan** | 1879 ms | 0,50 |

**Keseluruhan: WER 0,376 (S=32, D=3, I=6, N=109), CER 0,184.** Dihitung dengan `jiwer` setelah normalisasi (huruf kecil, tanda baca dibuang).

## Pola kesalahan

| Pola | Contoh | Catatan |
|---|---|---|
| Istilah teknis / campur kode | query→kweri, tabel→label, reviewer→refiur, edge case→etxkesi, queue→QE, Backend→Backhand | Kalimat 6 (paling banyak Inggris) paling rusak. Kandidat tuas: `initial_prompt` berisi istilah dari pertanyaan lowongan |
| Angka | delapan juta → 80 juta, tiga → 3 | "80 juta" berbahaya secara produk; `salary_fit` sengaja memakai kolom `expected_salary`, bukan transkrip |
| Kata mirip bunyi | gaji→gadget, bug→bagi, tantangan terbesarnya→tantang entera besar nya, tim berlima→dimatikan orang | Kelemahan model umum tanpa konteks domain |
| Normalisasi ejaan | menambahkan→nambahkan, diskusi→didiskusikan | Sebagian karena cara baca; tetap dihitung WER |
| Outlier latency | kalimat 6a: 6,4 s, RTF 1,6 | Antrian 32 chunk; kemungkinan CPU sedang dipakai proses lain + backlog |

## Kesan subjektif (diisi mahasiswa)

- Partial terasa mengikuti / tersendat: ______
- Perubahan teks partial → final mengganggu / membantu: ______

## Tindak lanjut

1. Fase 3: worker terpisah partial/final (menghilangkan backlog).
2. Eksperimen: `initial_prompt` dari teks pertanyaan (dengan vs tanpa), `medium` untuk final, mikrofon headset vs laptop.
3. Mahasiswa mengisi kondisi ruangan dan kesan subjektif di berkas ini.
