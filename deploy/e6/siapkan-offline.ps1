# Siapkan paket pemasangan E6 OFFLINE (dijalankan di komputer yang ADA internet).
#
# Hasil: folder deploy\e6\paket\ berisi
#   wheels\                      semua paket Python (torch CUDA 12.6 + requirements-e6.txt)
#   model\trocr-small-handwritten\   model TrOCR (+-250 MB)
#   python-3.10.11-amd64.exe     installer Python (opsional, -TanpaInstallerPython untuk melewati)
# Salin seluruh folder proyek (atau minimal deploy\e6\paket) ke PC produksi, lalu
# jalankan pasang-e6.ps1 -Offline di sana.
#
# Pemakaian (PowerShell, dari folder proyek Laravel):
#   powershell -ExecutionPolicy Bypass -File deploy\e6\siapkan-offline.ps1 [-Cpu] [-TanpaInstallerPython]

param(
    [switch]$Cpu,                   # unduh torch versi CPU (tanpa GPU), jauh lebih kecil
    [switch]$TanpaInstallerPython,
    [string]$Python = ""            # python untuk menjalankan pip; default: py -3.10 atau python
)

$ErrorActionPreference = "Stop"
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
$paket = Join-Path $dir "paket"
$wheels = Join-Path $paket "wheels"
$model = Join-Path $paket "model\trocr-small-handwritten"
New-Item -ItemType Directory -Force $wheels, $model | Out-Null

. (Join-Path $dir "_umum.ps1")
$py = Cari-Python $Python
Write-Host "Python pengunduh: $py"

$torchIndex = if ($Cpu) { $TORCH_INDEX_CPU } else { $TORCH_INDEX_CUDA }
$platform = @("--only-binary=:all:", "--platform", "win_amd64", "--python-version", "3.10", "--implementation", "cp")

Write-Host "`n[1/3] Unduh torch $TORCH_VERSI ($torchIndex) ..."
& $py -m pip download "torch==$TORCH_VERSI" --index-url $torchIndex --extra-index-url https://pypi.org/simple @platform -d $wheels
if ($LASTEXITCODE -ne 0) { throw "Gagal mengunduh torch" }

Write-Host "`n[2/3] Unduh paket requirements-e6.txt ..."
& $py -m pip download -r (Join-Path $dir "requirements-e6.txt") @platform -d $wheels
if ($LASTEXITCODE -ne 0) { throw "Gagal mengunduh paket" }

Write-Host "`n[3/3] Unduh model $MODEL_REPO ..."
Unduh-Model $py $model

if (-not $TanpaInstallerPython) {
    $inst = Join-Path $paket "python-3.10.11-amd64.exe"
    if (-not (Test-Path $inst)) {
        Write-Host "`nUnduh installer Python 3.10.11 ..."
        Invoke-WebRequest "https://www.python.org/ftp/python/3.10.11/python-3.10.11-amd64.exe" -OutFile $inst -UseBasicParsing
    }
}

$mb = [math]::Round(((Get-ChildItem $paket -Recurse -File | Measure-Object Length -Sum).Sum / 1MB))
Write-Host "`nSelesai. Paket offline: $paket ($mb MB)"
Write-Host "Salin ke PC produksi, lalu: powershell -ExecutionPolicy Bypass -File deploy\e6\pasang-e6.ps1 -Offline"
