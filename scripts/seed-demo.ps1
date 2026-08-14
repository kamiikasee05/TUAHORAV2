# ============================================================
# TUAHORAV2 — seed-demo.ps1
# Sembra datos de demo en el Scheduler local (idempotente):
#   1. Crea los servicios de ejemplo que falten (por nombre).
#   2. Configura branding demo (marca, paleta, contacto).
# Uso:  & "E:\TUAHORAV2\scripts\seed-demo.ps1"
# Requiere el stack levantado (dev-up.ps1) o al menos el Scheduler en :3000.
# ============================================================

param(
    [string]$BaseUrl   = "http://127.0.0.1:3000",
    [string]$ApiKey    = "dev-key-local-123",
    [int]$ProviderId   = 5
)

$api = "$BaseUrl/api/v1"
$headers = @{ "X-API-Key" = $ApiKey; "Content-Type" = "application/json" }

# --- Servicios de ejemplo (salón de uñas) ---
$servicios = @(
    @{ name = "Esmaltado semipermanente";  duration = 60;  price = 18000; slotInterval = 15; description = "Esmaltado semipermanente con manicura completa y secado en lámpara LED." },
    @{ name = "Capping acrílico";          duration = 90;  price = 25000; slotInterval = 15; description = "Refuerzo con acrílico sobre uña natural para un acabado resistente." },
    @{ name = "Esculpidas en gel";         duration = 120; price = 30000; slotInterval = 15; description = "Uñas esculpidas en gel a medida, largas y resistentes." },
    @{ name = "Retiro de esmalte";         duration = 30;  price = 8000;  slotInterval = 15; description = "Retiro de esmalte o acrílico con cuidado de la uña natural." },
    @{ name = "Manicure clásica";          duration = 45;  price = 12000; slotInterval = 15; description = "Manicura tradicional con corte, limado y esmaltado común." },
    @{ name = "Pedicure completo";         duration = 60;  price = 20000; slotInterval = 15; description = "Pedicura completa: exfoliación, hidratación y esmaltado." }
)

# --- Branding demo ---
$branding = @{
    brand = @{
        name        = "Nails by Laura"
        tagline     = "Realzá tu estilo en cada detalle"
        address     = "Belgrano 456, Chamical, La Rioja"
        whatsapp    = "5493826403110"
        instagram   = "@nailsbylaura"
        profesional = "Laura"
    }
    colors = @{
        primary    = "#884e4f"
        secondary  = "#f5f0f0"
        accent     = "#914758"
        text       = "#1b1c1c"
        background = "#fcf9f8"
    }
}

Write-Host "==============================================" -ForegroundColor Cyan
Write-Host " TUAHORAV2 - seed de datos demo" -ForegroundColor Cyan
Write-Host "==============================================" -ForegroundColor Cyan

# --- 1. Servicios (idempotente por nombre) ---
try {
    $existentes = @(Invoke-RestMethod -Uri "$api/services" -Method GET -TimeoutSec 10)
} catch {
    Write-Host "[ERROR] No pude consultar $api/services. El Scheduler esta corriendo?" -ForegroundColor Red
    Write-Host "        $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}

$nombresExistentes = @($existentes | ForEach-Object { $_.name })
$creados = 0
$yaExistentes = 0

foreach ($s in $servicios) {
    if ($s.name -in $nombresExistentes) {
        Write-Host "[SKIP] Servicio ya existe: $($s.name)" -ForegroundColor DarkGray
        $yaExistentes++
        continue
    }
    $body = @{
        name                 = $s.name
        duration             = $s.duration
        price                = $s.price
        currency             = "ARS"
        description          = $s.description
        slotInterval         = $s.slotInterval
        attendantsNumber     = 1
        serviceCategoryId    = $null
    } | ConvertTo-Json
    try {
        $r = Invoke-RestMethod -Uri "$api/services" -Method POST -Headers $headers -Body $body -TimeoutSec 10
        Write-Host "[OK] Servicio creado: $($r.name) ($($r.duration) min, `$$($r.price))" -ForegroundColor Green
        $creados++
    } catch {
        Write-Host "[ERROR] No pude crear '$($s.name)': $($_.Exception.Message)" -ForegroundColor Red
    }
}

# --- 2. Branding ---
try {
    $body = $branding | ConvertTo-Json -Depth 5
    $r = Invoke-RestMethod -Uri "$api/branding" -Method PUT -Headers $headers -Body $body -TimeoutSec 10
    Write-Host "[OK] Branding guardado: $($r.config.brand.name) - $($r.config.brand.tagline)" -ForegroundColor Green
} catch {
    Write-Host "[ERROR] No pude guardar branding: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""
Write-Host "Resumen: $creados servicios creados, $yaExistentes ya estaban." -ForegroundColor Cyan
Write-Host "Verifica en: $BaseUrl/" -ForegroundColor Cyan
