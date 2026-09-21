# Panduan instalasi dan menjalankan

Diuji pada Windows 11 dengan XAMPP. Perintah dijalankan di PowerShell dari folder repo.

## Prasyarat

| Komponen | Versi yang dipakai | Cara cek |
|---|---|---|
| PHP | 8.2.12 (XAMPP) — minimal 8.2 | `php --version` |
| Composer | 2.8 | `composer --version` |
| Node.js + npm | 24 — minimal 20 | `node --version` |
| MySQL/MariaDB | MariaDB 10.4 (XAMPP) | XAMPP Control Panel → MySQL **Start** |
| Python (untuk `services/`, Fase 2+) | 3.12 | `py -3.12 --version` |
| FFmpeg (Fase 2+) | 9.x | `ffmpeg -version` |

Ekstensi PHP yang harus aktif di `php.ini`: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`, `curl` (semua sudah aktif di XAMPP standar).

## Aplikasi web

```powershell
git clone https://github.com/MichLoverz/hrd-speech-web-final-project.git
cd hrd-speech-web-final-project\web

composer install
npm install
copy .env.example .env
php artisan key:generate
```

Buat database (sekali):

```powershell
& "C:\xampp\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE hirenote CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

`.env.example` sudah berisi `DB_CONNECTION=mysql`, `DB_DATABASE=hirenote`, `DB_USERNAME=root`, `DB_PASSWORD=` (kosong, default XAMPP). Sesuaikan jika berbeda.

```powershell
php artisan migrate:fresh --seed
npm run build
php artisan serve
```

Buka <http://127.0.0.1:8000>. Akun contoh:

| Email | Kata sandi | Peran |
|---|---|---|
| `admin@hirenote.test` | `password` | admin |
| `hrd@hirenote.test` | `password` | hrd |
| `interviewer@hirenote.test` | `password` | interviewer |

## Saat mengembangkan

- `npm run dev` (terminal terpisah) — Vite hot reload untuk CSS/Blade.
- `php artisan test` — 40 tes, memakai SQLite in-memory, tidak menyentuh database MariaDB.
- `php artisan queue:work` — baru diperlukan di Fase 4 (analisis kartu skor).

## Masalah umum

| Gejala | Penyebab | Solusi |
|---|---|---|
| `SQLSTATE[HY000] [2002]` saat migrate | MySQL XAMPP belum jalan | Start MySQL di XAMPP Control Panel |
| Halaman tampil tanpa CSS | `public/build/` belum ada | `npm run build` |
| Unggah CV gagal "file too large" | `upload_max_filesize` PHP < 5 MB | naikkan di `php.ini` XAMPP, restart |
| `Class "App\...\Controller" ... authorize` | `composer install` belum dijalankan | `composer install` |

## Menjalankan dengan ASR (`services/`)

Sekali:

```powershell
cd services
py -3.12 -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
python scripts/download_models.py      # model speaker embedding untuk pembedaan suara
```

Setiap kali (dua terminal):

```powershell
# terminal 1 — ASR service (model diunduh saat pertama kali, ±0,5 GB)
cd services
.venv\Scripts\activate
uvicorn app.main:app --host 127.0.0.1 --port 8765

# terminal 2 — aplikasi web
cd web
php artisan serve
```

Cek: <http://127.0.0.1:8765/health> harus `{"ok": true, ...}`. Alamat service diatur di `web/.env` (`ASR_HTTP_URL`, `ASR_WS_URL`, `ASR_USE_PROMPT`).

Perekaman di browser memakai mikrofon lewat `getUserMedia`, yang hanya diizinkan pada `localhost`/`127.0.0.1` atau HTTPS. Untuk mengakses dari HP di jaringan yang sama diperlukan HTTPS lokal (Fase 5).
