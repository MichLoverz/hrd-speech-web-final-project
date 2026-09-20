# ADR-001: Monorepo dengan folder `web/`, `asr/`, `tools/`, `docs/`

Tanggal: 2026-09-21 · Status: diterima

## Konteks

Proyek dinilai oleh tiga mata kuliah dan terdiri dari dua aplikasi yang bicara lewat WebSocket: aplikasi Laravel dan ASR service Python. Perlu diputuskan apakah keduanya di satu repo atau dua repo.

## Opsi yang dipertimbangkan

1. Dua repo terpisah (`hirenote-web`, `hirenote-asr`).
2. Satu repo (monorepo) dengan subfolder per komponen.

## Keputusan

Monorepo: `web/` (Laravel), `asr/` (Python), `tools/` (harness evaluasi), `docs/` (devlog, ADR, eksperimen, user testing).

## Alasan

- Penilai tiga mata kuliah cukup clone satu repo; panduan instalasi satu berkas.
- ADR dan devlog yang menyangkut integrasi (protokol WebSocket, format pesan, instrumentasi latency) tidak terpecah di dua tempat.
- Satu riwayat git untuk dipertanggungjawabkan saat demo — commit "ubah format pesan ASR" dan "sesuaikan parser di Laravel" terlihat berdampingan.

## Konsekuensi

- `.gitignore` harus mencakup dua ekosistem (vendor/, node_modules/, .venv/, models/).
- Tag rilis per fase (`v0.1-crud`, `v0.2-asr-prototype`, ...) berlaku untuk seluruh repo.
