# Starts everything for a demo over the ngrok tunnel, one window per process (docs/demo-tunnel.md).
# Needs: XAMPP MySQL running, web/.env in demo mode (ASR_PUBLIC_HTTP_URL = https://<ngrok domain>).
# Run from anywhere:  powershell -ExecutionPolicy Bypass -File deploy\tunnel\start-demo.ps1

$root = (Resolve-Path "$PSScriptRoot\..\..").Path

# Terminals opened before winget installed ngrok/caddy keep a stale PATH; the child windows inherit this one.
$env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')

if (-not (Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue)) {
    Write-Host 'MySQL tidak jalan. Start MySQL di XAMPP Control Panel dulu.' -ForegroundColor Red
    exit 1
}

$line = Select-String -Path "$root\web\.env" -Pattern '^ASR_PUBLIC_HTTP_URL=(https://\S+)' | Select-Object -First 1
if (-not $line) {
    Write-Host 'web/.env belum mode demo: ASR_PUBLIC_HTTP_URL=https://<domain-ngrok> tidak ada.' -ForegroundColor Red
    exit 1
}
$url = $line.Matches[0].Groups[1].Value.TrimEnd('/')

function Start-Window($title, $dir, $command) {
    Start-Process powershell -WorkingDirectory $dir -ArgumentList '-NoExit', '-ExecutionPolicy', 'Bypass', '-Command',
        "`$Host.UI.RawUI.WindowTitle = '$title'; $command"
}

Start-Window 'HireNote ASR' "$root\services" '.venv\Scripts\activate; uvicorn app.main:app --host 127.0.0.1 --port 8765'
Start-Window 'HireNote Laravel' "$root\web" 'php artisan serve'
Start-Window 'HireNote Queue' "$root\web" 'php artisan queue:work'
Start-Window 'HireNote Caddy' $root 'caddy run --config deploy/tunnel/Caddyfile'
Start-Window 'HireNote ngrok' $root "ngrok http 8080 --url=$url"

Write-Host "Lima jendela dibuka. Tunggu ASR menulis 'models loaded', lalu buka $url" -ForegroundColor Green
Write-Host 'Selesai demo: tutup jendela ngrok dulu, lalu yang lain.'
