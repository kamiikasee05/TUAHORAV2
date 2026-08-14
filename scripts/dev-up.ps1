# ============================================================
# TUAHORAV2 — dev-up.ps1
# Arranca el stack local completo de desarrollo:
#   1. Scheduler Node (API, puerto 3000) — node dist/index.js
#   2. Admin PHP (dev server, puerto 8080) — php -S
# Verifica que ambos respondan y limpia los procesos al salir.
# Uso:  & "E:\TUAHORAV2\scripts\dev-up.ps1"
# ============================================================

# --- Configuración (editar acá si hace falta) ---
$phpExe = "C:\Users\KAMIIKASEE\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
$root   = "E:\TUAHORAV2"
$schedDir = Join-Path $root "scheduler"
$phpDir   = Join-Path $root "php"

# Hash bcrypt generado con: php -r "echo password_hash('admin123', PASSWORD_BCRYPT);"
# Clave de dev del admin: admin123
$adminPasswordHash = '$2y$12$YYegZ/VDEmhHX8XaYB50xeOVcE/moA53HGQCimxflfkKd1nut7Pki'

# --- Variables de entorno para esta sesión (heredadas por los procesos hijos) ---
$env:PORT               = "3000"
$env:API_KEY            = "dev-key-local-123"
$env:SCHEDULER_API_KEY  = "dev-key-local-123"
$env:SCHEDULER_URL      = "http://127.0.0.1:3000/api/v1"
$env:PROVIDER_ID        = "5"
$env:CORS_ORIGIN        = "http://localhost:8080"
$env:ADMIN_PASSWORD_HASH = $adminPasswordHash
$env:NODE_ENV           = "development"
$env:DATA_DIR           = "./data"
$env:LOG_LEVEL          = "debug"

# --- Pre-checks ---
if (-not (Test-Path -LiteralPath $phpExe)) {
    Write-Host "[ERROR] No se encontro php.exe en: $phpExe" -ForegroundColor Red
    exit 1
}
if (-not (Test-Path -LiteralPath (Join-Path $schedDir "dist\index.js"))) {
    Write-Host "[ERROR] Falta dist/index.js. Compilar con: npm run build (en $schedDir)" -ForegroundColor Red
    exit 1
}

$children = @()

# Manija de salida: matar hijos sin importar cómo termine el script
trap {
    Write-Host "`n[INFO] Limpiando procesos hijos..." -ForegroundColor Yellow
    foreach ($p in $children) {
        if (-not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
    }
    exit 1
}

function Wait-Http($url, $name, $attempts = 30) {
    for ($i = 1; $i -le $attempts; $i++) {
        try {
            $r = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 2
            Write-Host "[OK] $name responde ($($r.StatusCode)) -> $url" -ForegroundColor Green
            return $r
        } catch {
            Start-Sleep -Milliseconds 500
        }
    }
    Write-Host "[ERROR] $name no respondio tras $attempts intentos: $url" -ForegroundColor Red
    return $null
}

Write-Host "==============================================" -ForegroundColor Cyan
Write-Host " TUAHORAV2 — dev stack" -ForegroundColor Cyan
Write-Host "==============================================" -ForegroundColor Cyan

# --- Arrancar Scheduler (Node, puerto 3000) ---
Write-Host "[INFO] Arrancando Scheduler (node dist/index.js)..." -ForegroundColor Yellow
$pSched = Start-Process -FilePath "node" -ArgumentList "dist/index.js" `
    -WorkingDirectory $schedDir -PassThru -WindowStyle Hidden
$children += $pSched
Write-Host "[INFO] Scheduler PID $($pSched.Id) lanzado (log en hidden window)."

# --- Arrancar Admin PHP (dev server, puerto 8080) ---
Write-Host "[INFO] Arrancando PHP dev server..." -ForegroundColor Yellow
$pPhp = Start-Process -FilePath $phpExe `
    -ArgumentList "-S", "127.0.0.1:8080", "-t", $phpDir `
    -PassThru -WindowStyle Hidden
$children += $pPhp
Write-Host "[INFO] PHP PID $($pPhp.Id) lanzado."

# --- Verificar arranque ---
Wait-Http "http://127.0.0.1:3000/health" "Scheduler /health"
Wait-Http "http://127.0.0.1:8080/"       "PHP index"

# --- Mostrar URLs ---
Write-Host ""
Write-Host "Stack levantado. URLs:" -ForegroundColor Cyan
Write-Host "  Scheduler API : http://127.0.0.1:3000/api/v1  (health: http://127.0.0.1:3000/health)"
Write-Host "  Admin PHP     : http://127.0.0.1:8080/admin/  (pass: admin123)"
Write-Host "  API key       : dev-key-local-123"
Write-Host ""
Write-Host "Presiona Ctrl+C para detener el stack (mata Scheduler y PHP)." -ForegroundColor Yellow

# Mantener el script vivo mientras el scheduler corra.
# Ctrl+C dispara el trap / finally que limpia los procesos hijos.
while (-not $pSched.HasExited) {
    Start-Sleep -Seconds 2
}

Write-Host "[INFO] El Scheduler termino solo. Cerrando..." -ForegroundColor Yellow
foreach ($p in $children) {
    if (-not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
}
