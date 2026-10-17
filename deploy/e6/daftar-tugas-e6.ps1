# Daftarkan layanan AI Baca Kertas (E6) ke Task Scheduler supaya SELALU HIDUP:
# - menyala saat komputer hidup (tanpa perlu login),
# - dicek ulang tiap 5 menit (bila layanan mati/berhenti, dinyalakan lagi;
#   bila masih hidup, pemicu diabaikan),
# - tanpa batas waktu jalan.
# Perlu PowerShell "Run as Administrator".
#
# Pemakaian (dari folder proyek Laravel):
#   powershell -ExecutionPolicy Bypass -File deploy\e6\daftar-tugas-e6.ps1 [-Device cuda|cpu|auto] [-SebagaiSaya]
#   powershell -ExecutionPolicy Bypass -File deploy\e6\daftar-tugas-e6.ps1 -Hapus
#
# Akun: default SYSTEM. -SebagaiSaya = akun Anda sekarang (tanpa password, hanya akses lokal);
# pakai ini bila file di storage\ tidak bisa ditulis bersama oleh web server & SYSTEM.

param(
    [ValidateSet("auto", "cuda", "cpu")][string]$Device = "cuda",
    [switch]$SebagaiSaya,
    [switch]$Hapus,
    [string]$Nama = "AI Baca Kertas E6"
)

$ErrorActionPreference = "Stop"
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $dir "_umum.ps1")

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw "Jalankan PowerShell sebagai Administrator (klik kanan > Run as administrator)." }

if ($Hapus) {
    if (Get-ScheduledTask -TaskName $Nama -ErrorAction SilentlyContinue) {
        Stop-ScheduledTask -TaskName $Nama -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $Nama -Confirm:$false
        Write-Host "Tugas '$Nama' dihapus. (Proses python yang masih jalan berhenti sendiri bila Anda menutupnya lewat Task Manager.)"
    } else {
        Write-Host "Tugas '$Nama' tidak ada."
    }
    return
}

if (-not (Test-Path $VENV_PY)) { throw "venv E6 belum ada ($VENV_PY). Jalankan pasang-e6.ps1 dulu." }
if (-not (Test-Path (Join-Path $MODEL_DIR "config.json"))) { throw "Model belum ada ($MODEL_DIR). Jalankan pasang-e6.ps1 dulu." }

$cmd = Join-Path $dir "jalankan-layanan-e6.cmd"
$aksi = New-ScheduledTaskAction -Execute "cmd.exe" -Argument "/c `"`"$cmd`" $Device`"" -WorkingDirectory $E6_DIR

$saatNyala = New-ScheduledTaskTrigger -AtStartup
$tiap5 = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5)
$aturan = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)

if ($SebagaiSaya) {
    $siapa = "$env:USERDOMAIN\$env:USERNAME"
    $prinsipal = New-ScheduledTaskPrincipal -UserId $siapa -LogonType S4U -RunLevel Limited
} else {
    $siapa = "SYSTEM"
    $prinsipal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
}

Register-ScheduledTask -TaskName $Nama -Action $aksi -Trigger @($saatNyala, $tiap5) -Settings $aturan -Principal $prinsipal `
    -Description "Layanan antrian AI Baca Kertas (Eksplorasi 6) untuk halaman Tambah Data. Dibuat oleh deploy\e6\daftar-tugas-e6.ps1" -Force | Out-Null
Write-Host "Tugas '$Nama' terdaftar (akun: $siapa, device: $Device)."

Start-ScheduledTask -TaskName $Nama
Write-Host "Menyalakan layanan ..."
$detak = Join-Path $PROYEK "storage\app\scan-history\layanan.json"
$batas = (Get-Date).AddSeconds(60)
$hidup = $false
while ((Get-Date) -lt $batas) {
    Start-Sleep -Seconds 3
    if (Test-Path $detak) {
        try {
            $info = Get-Content $detak -Raw | ConvertFrom-Json
            $umur = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() - [int64]$info.detak_unix
            if ($umur -lt 20 -and $info.idle_menit -eq 0) { $hidup = $true; break }
        } catch {}
    }
}
if ($hidup) {
    Write-Host "Layanan HIDUP (pid $($info.pid), device $($info.device)). Model dimuat saat foto pertama masuk." -ForegroundColor Green
} else {
    Write-Host "Layanan belum terlihat hidup dalam 60 detik. Cek storage\logs\layanan-e6.log dan Task Scheduler > '$Nama' > History." -ForegroundColor Yellow
    Write-Host "(Bila layanan.json menunjukkan layanan lain yang dinyalakan Laravel, tugas ini akan mengambil alih setelah layanan itu berhenti.)"
}
