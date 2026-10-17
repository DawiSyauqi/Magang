@echo off
rem Jalankan layanan AI Baca Kertas (E6) yang SELALU HIDUP (--idle-menit 0).
rem Dipakai oleh Task Scheduler (daftar-tugas-e6.ps1); bisa juga diklik manual.
rem Argumen 1 (opsional): device auto ^| cuda ^| cpu  (default auto)
rem Bila layanan lain sudah hidup (mis. dinyalakan Laravel), skrip ini langsung keluar.
setlocal
for %%I in ("%~dp0..\..") do set "PROYEK=%%~fI"
set "E6=%PROYEK%\scripts\eksplorasi6"
set "PY=%E6%\.venv\Scripts\python.exe"
set "DEVICE=%~1"
if "%DEVICE%"=="" set "DEVICE=auto"
if not exist "%PY%" (
    echo venv E6 belum dipasang: %PY%
    echo Jalankan dulu deploy\e6\pasang-e6.ps1
    exit /b 1
)
set PYTHONUTF8=1
set PYTHONIOENCODING=utf-8
set HF_HUB_OFFLINE=1
set TRANSFORMERS_OFFLINE=1
if not exist "%PROYEK%\storage\logs" mkdir "%PROYEK%\storage\logs"
cd /d "%E6%"
"%PY%" -B layanan_e6.py --root "%PROYEK%\storage\app\scan-history" --idle-menit 0 --device %DEVICE% --trocr-model "%E6%\model\trocr-small-handwritten" --log "%PROYEK%\storage\logs\layanan-e6.log"
exit /b %ERRORLEVEL%
