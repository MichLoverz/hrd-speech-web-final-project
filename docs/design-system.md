# Sistem desain HireNote

Tanggal: 2026-09-21. Token ada di `web/tailwind.config.js`, kelas komponen di `web/resources/css/app.css`.

## Gagasan dasar

Pewawancara HRD mencatat di kertas sambil kandidat bicara, lalu menandai kutipan penting dengan stabilo. Transkrip adalah catatan resmi. Semua keputusan visual diturunkan dari situ, bukan dari template dashboard.

## Warna

| Token | Hex | Peran |
|---|---|---|
| `paper` | `#F4F5F1` | latar halaman (abu-hijau pucat, seperti kertas daur ulang; sengaja bukan krem hangat) |
| `sheet` | `#FFFFFF` | area konten |
| `rule` | `#DEE1DA` | garis 1 px untuk tabel dan pemisah bagian |
| `ink` / `ink-soft` | `#1F2B3A` / `#3D4A5B` | teks utama, tombol utama |
| `pencil` / `pencil-light` | `#7C838C` / `#B4B9C0` | teks provisional (transkrip parsial), keterangan sekunder |
| `highlighter` | `#F5E663` | satu-satunya elemen berani: kutipan bukti di transkrip, dan "Note" di logo |
| `stamp` | `#2E6B4E` | status selesai/disetujui |
| `danger` | `#9E3223` | aksi destruktif |

## Tipografi

- **IBM Plex Sans** (400/500/600) untuk seluruh UI. Dirancang untuk antarmuka, punya *tabular figures* (`font-feature-settings: 'tnum'`) sehingga angka rupiah rapi, dan secara historis diturunkan dari huruf mesin ketik IBM — nyambung dengan transkrip.
- **Courier Prime** hanya untuk isi transkrip (`.transcript`). Transkrip wawancara punya konvensi visual mesin ketik/deposisi; ini pilihan dari konten, bukan dekorasi. Parsial ditampilkan warna pensil, final berubah menjadi tinta.
- Skala: 13 / 14 / 15 / 17 / 21 / 26 / 33 px. Body 15 px, line-height 1.55. Judul semibold, sentence case, tanpa label all-caps.
- Font di-*self-host* lewat `@fontsource/*` supaya demo tetap jalan tanpa internet.

Catatan revisi: awalnya Atkinson Hyperlegible. Diganti setelah screenshot pertama karena angka nol bergaris miringnya membuat `Rp 7.000.000` sulit dibaca.

## Tata letak

- Satu "lembar" putih di atas kertas, rata kiri, lebar maksimum 1080 px. Bagian dipisah garis `rule`, bukan kartu-kartu berbayang.
- Tabel = *ledger*: hanya garis horizontal, header dengan garis tinta.
- Radius 3 px untuk kontrol, 0 untuk lembar. Tanpa bayangan.
- Navigasi bar atas, tiga item + akun.
- Formulir satu kolom, label di atas, lebar maksimum 68 karakter.
- Aksi baris (Ubah/Hapus di daftar) warna pensil, merah hanya saat hover — supaya merah tidak berulang di tiap baris.

## Prinsip

1. Yang berani cuma stabilo dan Courier di transkrip; sisanya tenang.
2. Tidak ada animasi saat halaman dimuat. Satu-satunya gerak: teks transkrip yang muncul karena orang berbicara. `prefers-reduced-motion` dihormati.
3. Tulisan UI bahasa Indonesia, kalimat aktif, tombol menyebut hasilnya ("Simpan lowongan", "Buat sesi draf").
4. Halaman kosong = ajakan bertindak. Error = apa yang salah dan cara memperbaikinya.
5. Status ditulis sebagai kata dengan titik kecil (`.status`), bukan pil berwarna.

## Kelas komponen

`.sheet`, `.sheet-section`, `.ledger`, `.btn-primary`, `.btn-quiet`, `.btn-danger`, `.btn-link`, `.row-action`, `.row-action-danger`, `.field*`, `.status*`, `.transcript`, `.transcript-partial`, `.highlight`, `.notice`, `.empty`.

Komponen Blade: `x-page` (judul, subjudul, tautan kembali, slot `actions`), `x-status`, `x-field`, `x-confirm-delete`.
