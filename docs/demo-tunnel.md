# Demo lewat tunnel ngrok

Untuk presentasi di komputer lain (mis. komputer dosen) tanpa memindahkan kabel HDMI. Semua tetap berjalan di laptop; ngrok memberi alamat HTTPS publik yang meneruskan ke laptop. Laptop dan komputer yang membuka link **tidak perlu satu jaringan**.

```
browser (komputer mana saja) --HTTPS--> ngrok --> laptop :8080 (Caddy) --> Laravel :8000
                                                                       \-> ASR :8765 (/asr/stream, /health)
```

Ini cara **demo**, bukan deployment: data lewat server ngrok (pihak ketiga yang tidak disebut di teks consent), jadi hanya untuk sesi data uji. Wawancara peserta sungguhan tidak lewat tunnel.

## Sekali saja

```powershell
winget install CaddyServer.Caddy
winget install Ngrok.Ngrok
ngrok update                        # versi winget bisa tertinggal
ngrok config add-authtoken <TOKEN>  # dari dashboard ngrok -> Your Authtoken; jangan ditempel di chat/repo
```

Domain gratis ada di dashboard ngrok -> Domains (satu per akun, tetap).

## `web/.env` saat demo

```
APP_DEBUG=false
APP_URL=https://<domain-ngrok>
ASR_PUBLIC_HTTP_URL=https://<domain-ngrok>
ASR_WS_URL=wss://<domain-ngrok>/asr/stream
```

`ASR_HTTP_URL` tetap `http://127.0.0.1:8765` (dipakai Laravel sendiri). Setelah mengubah `.env`, restart `php artisan serve` dan `queue:work`.

**Kembali ke mode lokal:** `APP_DEBUG=true`, `APP_URL=http://localhost`, hapus `ASR_PUBLIC_HTTP_URL` (atau salin balik `web/.env.backup`), `ASR_WS_URL=ws://127.0.0.1:8765/asr/stream`.

## Menjalankan (5 terminal + XAMPP MySQL)

```powershell
# 1. services/
.venv\Scripts\activate; uvicorn app.main:app --host 127.0.0.1 --port 8765
# 2. web/
npm run build; php artisan serve
# 3. web/
php artisan queue:work
# 4. root repo
caddy run --config deploy/tunnel/Caddyfile
# 5.
ngrok http 8080 --url=https://<domain-ngrok>
```

Sesi demo satu pertanyaan (data uji, bisa direset kapan saja): `php artisan hirenote:prototype-demo --single`.

## Cek sebelum tampil

1. Buka link dari HP dengan **data seluler, WiFi mati** (meniru komputer dosen). Klik "Visit Site" di halaman peringatan ngrok (sekali per browser, berlaku 7 hari).
2. Login, buka sesi, buka kartu skor. Alamat di browser harus tetap `https://`.
3. Kalau perekaman ikut didemokan: halaman rekam harus menunjukkan service ASR siap.

## Keamanan selama tunnel menyala

Yang terbuka hanya website (port 8080), bukan isi laptop. Tapi siapa pun yang tahu link bisa membuka halaman login:

- `APP_DEBUG=false` wajib (halaman error mode debug bisa membocorkan isi konfigurasi).
- Ganti kata sandi akun seeder (semuanya `password`).
- Matikan ngrok (`Ctrl+C`) setelah presentasi.

## Cadangan

- WiFi kampus bermasalah -> pindahkan laptop ke hotspot; link tidak berubah.
- Semuanya gagal -> screenshot halaman sesi dan kartu skor di slide.
