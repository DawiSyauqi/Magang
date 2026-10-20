# Nyalakan ulang layanan AI Baca Kertas (E6) dengan benar.
#
# "End" di Task Scheduler hanya menghentikan cmd.exe; proses python.exe layanan
# bisa tetap hidup memakai kode LAMA di memori, lalu layanan baru keluar karena
# mengira layanan lain masih berjalan. Skrip ini menghentikan proses python
# layanan, lalu menyalakan tugas Task Scheduler lagi.
#
# Sejak versi kode 20-10-2026 layanan juga berhenti sendiri bila file kodenya
# berubah (git pull), jadi skrip ini biasanya hanya perlu dipakai sekali.
#
# Pemakaian (PowerShell "Run as administrator", dari folder proyek):
#   powershell -ExecutionPolicy Bypass -File deploy\e6\restart-layanan-e6.ps1

param([string]$Nama = "AI Baca Kertas E6")

$ErrorActionPreference = "Stop"
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw "Jalankan PowerShell sebagai Administrator (layanan berjalan dengan akun SYSTEM)." }

$tugas = Get-ScheduledTask -TaskName $Nama -ErrorAction SilentlyContinue
if ($tugas) { Stop-ScheduledTask -TaskName $Nama -ErrorAction SilentlyContinue }

$proses = Get-CimInstance Win32_Process -Filter "Name='python.exe'" | Where-Object { $_.CommandLine -match 'layanan_e6' }
foreach ($p in $proses) {
    Write-Host "Menghentikan python layanan (pid $($p.ProcessId))"
    Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
}
if (-not $proses) { Write-Host "Tidak ada proses layanan yang berjalan." }
Start-Sleep -Seconds 2

if ($tugas) {
    Start-ScheduledTask -TaskName $Nama
    Write-Host "Tugas '$Nama' dinyalakan lagi. Tunggu ±10 detik lalu cek storage\app\scan-history\layanan.json (detak baru, versi_kode terisi)."
} else {
    Write-Host "Tugas '$Nama' tidak ditemukan. Layanan akan dinyalakan Laravel saat ada foto masuk, atau daftarkan dengan daftar-tugas-e6.ps1."
}
