# Pasang lingkungan Python AI Baca Kertas (Eksplorasi 6) di PC ini.
#
# Yang dibuat (semua di dalam folder proyek, Python global TIDAK diubah):
#   scripts\eksplorasi6\.venv\                        venv khusus E6
#   scripts\eksplorasi6\model\trocr-small-handwritten\  model TrOCR lokal
# Lalu menjalankan deploy\e6\cek_e6.py (uji baca 1 foto contoh).
#
# Pemakaian (PowerShell, dari folder proyek Laravel):
#   powershell -ExecutionPolicy Bypass -File deploy\e6\pasang-e6.ps1            # online, GPU
#   powershell -ExecutionPolicy Bypass -File deploy\e6\pasang-e6.ps1 -Offline   # dari deploy\e6\paket (siapkan-offline.ps1)
#   ... -Cpu        torch versi CPU (PC tanpa GPU NVIDIA)
#   ... -Ulang      hapus venv lama dulu
#   ... -Python "C:\Path\python.exe"   pilih python 3.10 tertentu

param(
    [switch]$Offline,
    [switch]$Cpu,
    [switch]$Ulang,
    [string]$Python = ""
)

$ErrorActionPreference = "Stop"
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $dir "_umum.ps1")
$paket = Join-Path $dir "paket"
$req = Join-Path $dir "requirements-e6.txt"

if ($Offline -and -not (Test-Path (Join-Path $paket "wheels"))) {
    throw "Folder $paket\wheels tidak ada. Jalankan siapkan-offline.ps1 di komputer yang ada internet, lalu salin folder paket ke sini."
}

$py = Cari-Python $Python
Write-Host "Python dasar : $py"
Write-Host "Proyek       : $PROYEK"

# ---------------------------------------------------------------- 1. venv
if ($Ulang -and (Test-Path $VENV)) {
    Write-Host "`n[1/4] Menghapus venv lama ..."
    Remove-Item -Recurse -Force $VENV
}
if (-not (Test-Path $VENV_PY)) {
    Write-Host "`n[1/4] Membuat venv $VENV ..."
    & $py -m venv $VENV
    if ($LASTEXITCODE -ne 0) { throw "Gagal membuat venv" }
} else {
    Write-Host "`n[1/4] venv sudah ada: $VENV (pakai -Ulang untuk membuat ulang)"
}

# ---------------------------------------------------------------- 2. paket
Write-Host "`n[2/4] Memasang paket Python ..."
if ($Offline) {
    $sumber = @("--no-index", "--find-links", (Join-Path $paket "wheels"))
    & $VENV_PY -m pip install @sumber "torch==$TORCH_VERSI"
    if ($LASTEXITCODE -ne 0) { throw "Gagal memasang torch dari paket offline" }
    & $VENV_PY -m pip install @sumber -r $req
} else {
    & $VENV_PY -m pip install --upgrade pip
    $torchIndex = if ($Cpu) { $TORCH_INDEX_CPU } else { $TORCH_INDEX_CUDA }
    & $VENV_PY -m pip install "torch==$TORCH_VERSI" --index-url $torchIndex
    if ($LASTEXITCODE -ne 0) { throw "Gagal memasang torch dari $torchIndex" }
    & $VENV_PY -m pip install -r $req
}
if ($LASTEXITCODE -ne 0) { throw "Gagal memasang requirements-e6.txt" }

# ---------------------------------------------------------------- 3. model
Write-Host "`n[3/4] Model TrOCR -> $MODEL_DIR"
$modelPaket = Join-Path $paket "model\trocr-small-handwritten"
if ($Offline) {
    if (-not (Test-Path (Join-Path $modelPaket "config.json"))) { throw "Model tidak ada di $modelPaket" }
    New-Item -ItemType Directory -Force $MODEL_DIR | Out-Null
    Copy-Item -Recurse -Force (Join-Path $modelPaket "*") $MODEL_DIR
} elseif (Test-Path (Join-Path $MODEL_DIR "config.json")) {
    Write-Host "  sudah ada, dilewati"
} else {
    Unduh-Model $VENV_PY $MODEL_DIR
}

# ---------------------------------------------------------------- 4. cek
Write-Host "`n[4/4] Cek lingkungan + uji baca foto contoh ..."
$env:PYTHONUTF8 = "1"
$dev = if ($Cpu) { "cpu" } else { "auto" }
& $VENV_PY (Join-Path $dir "cek_e6.py") --device $dev --model $MODEL_DIR
$ok = ($LASTEXITCODE -eq 0)

Write-Host ""
if ($ok) {
    Write-Host "Pemasangan selesai." -ForegroundColor Green
    Write-Host "Langkah berikutnya (lihat deploy\e6\PANDUAN.md):"
    Write-Host "  1. Isi .env: E6_MODE=python, E6_DEVICE=$(if ($Cpu) {'cpu'} else {'cuda'})"
    Write-Host "  2. Daftarkan layanan: powershell -ExecutionPolicy Bypass -File deploy\e6\daftar-tugas-e6.ps1  (sebagai Administrator)"
} else {
    Write-Host "Pemasangan selesai TAPI cek gagal - lihat pesan di atas & bagian 'Masalah umum' di PANDUAN.md." -ForegroundColor Yellow
    exit 1
}
