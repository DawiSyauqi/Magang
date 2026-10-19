# Laporan Pemasangan AI Baca Kertas (Eksplorasi 6) di PC Produksi
Tanggal/Waktu: 2026-10-19 07:35 WIB  
Target PC: DEV-PC (Windows, Intel i7-4790, NVIDIA GeForce RTX 3060)  

---

## 1. Tabel Status Per Langkah (0–6)

| Langkah | Deskripsi | Status | Bukti / Catatan Penting |
|---|---|---|---|
| **0. Periksa Keadaan** | Membaca status git, driver GPU, Python, internet, disk, dan konfigurasi | **LOLOS** | • Git: `2f3a2a9`, clean working tree<br>• GPU: RTX 3060 12GB, Driver 610.88, CUDA 13.3 (>=12.6 OK)<br>• Disk Free: C: 21.3 GB, D: 49.8 GB (cukup untuk ±5 GB)<br>• Python 3.10.11 64-bit dipasang atas izin pengguna (Batasan 6)<br>• Internet: Online (`pypi.org:443` sukses) |
| **1. Pasang Lingkungan Python** | Membuat venv, mengunduh PyTorch CUDA, pustaka pendukung, dan model TrOCR | **LOLOS** | • Venv: `scripts\eksplorasi6\.venv\` (Python 3.10.11)<br>• Paket: `torch==2.12.0+cu126`, `transformers==4.57.6`<br>• Model: `scripts\eksplorasi6\model\trocr-small-handwritten\`<br>• Output `cek_e6.py`: **96/96 kotak sama dengan hasil uji (SEMUA LOLOS)** |
| **2. Atur .env** | Menyetel mode E6 ke Python & CUDA, serta memperbarui cache konfigurasi | **LOLOS** | • Cadangan `.env`: `.env.backup-e6-20261019`<br>• Kunci: `E6_MODE=python` & `E6_DEVICE=cuda`<br>• Cache: `php artisan config:cache` diperbarui<br>• `config:show produksi.e6`: mode python, device cuda, model & venv valid |
| **3. Daftarkan Layanan Task Scheduler** | Mendaftarkan tugas `AI Baca Kertas E6` agar layanan selalu hidup | **LOLOS** | • Tugas: `AI Baca Kertas E6` (Akun SYSTEM, RunLevel Highest)<br>• PID Layanan: `10260`<br>• Isi `layanan.json`: `idle_menit: 0.0`, `device: "cuda"`, detak aktif diperbarui |
| **4. Uji Alur Ujung-ke-Ujung** | Uji alur via `php deploy/e6/uji_alur.php` tanpa web server dan database | **LOLOS** | • **Run 1 (Cold model)**: Selesai dalam **17.8 detik** di cuda, 96/96 kotak cocok, `idle_menit: 0`<br>• **Run 2 (Warm model di VRAM)**: Selesai dalam **12.5 detik** di cuda, 96/96 kotak cocok, `idle_menit: 0`<br>• Status: **LOLOS** |
| **5. Uji Ketahanan** | Pengujian terminasi proses & restart PC otomatis | **DILEWATI** | Dilewati atas konfirmasi pengguna guna menjaga kelancaran operasional PC server |
| **6. Uji dari Browser** | Uji unggah foto formulir asli dari HP lewat intranet | **DILEWATI** | Halaman draft dinonaktifkan di produksi. Pengujian dari browser dapat dilakukan pengguna sewaktu-waktu saat operasional |

---

## 2. Data Lingkungan Sistem

| Parameter | Nilai |
|---|---|
| **GPU** | NVIDIA GeForce RTX 3060 (12 GB GDDR6) |
| **Versi Driver NVIDIA** | `610.88` |
| **Versi CUDA (nvidia-smi)** | `13.3` |
| **Versi PyTorch & CUDA** | `torch 2.12.0+cu126` (CUDA 12.6 Runtime terintegrasi) |
| **Versi Python Dasar** | Python 3.10.11 (64-bit) (`C:\Users\User\AppData\Local\Programs\Python\Python310\python.exe`) |
| **Versi Python venv E6** | Python 3.10.11 (`D:\LARAVEL\Magang\scripts\eksplorasi6\.venv\Scripts\python.exe`) |
| **Mode Pemasangan** | **Online** (mengunduh wheel cu126 dan model TrOCR via jaringan) |
| **Akun Task Scheduler** | `SYSTEM` (Tugas: `AI Baca Kertas E6`, Pemicu: At Startup & Tiap 5 menit) |

---

## 3. Waktu Baca per Foto (Benchmark GPU RTX 3060)

| Pengujian | Detik Proses | Hasil Akurasi Kotak | Keterangan |
|---|---|---|---|
| `cek_e6.py --device cuda` | **11.6 detik** | **96 / 96 kotak (100%)** | Pemuatan model: 1.6 detik |
| `uji_alur.php` (Run 1) | **17.8 detik** | **96 / 96 kotak (100%)** | Model pertama kali diproses oleh antrian layanan |
| `uji_alur.php` (Run 2) | **12.5 detik** | **96 / 96 kotak (100%)** | Model sudah tersimpan dalam VRAM GPU (**5.3 detik lebih cepat**) |

---

## 4. Rincian Perubahan yang Dibuat

1. **Lingkungan & Pustaka Terisolasi (venv)**:
   - Folder venv baru: `scripts\eksplorasi6\.venv\`
   - Folder model lokal baru: `scripts\eksplorasi6\model\trocr-small-handwritten\` (berisi config, tokenizer, dan `pytorch_model.bin` sebesar ~250 MB).
2. **Konfigurasi `.env`**:
   - Lokasi cadangan `.env`: `D:\LARAVEL\Magang\.env.backup-e6-20261019`
   - Kunci yang ditambahkan di `.env`:
     ```env
     E6_MODE=python
     E6_DEVICE=cuda
     ```
   - Nilai konfigurasi lain tidak diubah.
3. **Task Scheduler Windows**:
   - Nama Tugas: `AI Baca Kertas E6`
   - Perintah eksekusi: `cmd.exe /c ""D:\LARAVEL\Magang\deploy\e6\jalankan-layanan-e6.cmd" cuda"`
   - Folder kerja: `D:\LARAVEL\Magang\scripts\eksplorasi6`
   - Pemicu: *At Startup* komputer dan pengulangan berkala setiap 5 menit.
4. **File Dokumentasi**:
   - Laporan ini dibuat di `deploy\e6\LAPORAN-PASANG-20261019.md` (diabaikan oleh git).

---

## 5. Masalah yang Ditemui dan Cara Penyelesaiannya

1. **Ketiadaan Python 3.10**:
   - *Masalah*: PC target hanya memiliki Python 3.14 dan Python 3.13 (Laragon). Pustaka PyTorch CUDA 12.6 dan TrOCR mensyaratkan Python 3.10 64-bit agar dependensi wheel terinstal stabil.
   - *Penyelesaian*: Sesuai ketentuan Batasan 6, agen meminta izin pengguna, lalu mengunduh dan memasang Python 3.10.11 64-bit resmi per-user ke folder `AppData\Local\Programs\Python\Python310`.
2. **Folder Kerja Awal PowerShell Administrator**:
   - *Masalah*: Eksekusi awal skrip registrasi Task Scheduler via PowerShell Administrator sempat gagal mencari file karena terminal default dibuka di `C:\Windows\System32`.
   - *Penyelesaian*: Memberikan instruksi perpindahan direktori (`cd D:\LARAVEL\Magang`) dan path absolut, sehingga registrasi tugas berjalan sukses.

---

## 6. Kesimpulan
Modul AI Baca Kertas (Eksplorasi 6) telah **terpasang sempurna dan siap digunakan di PC produksi**. Layanan berjalan otomatis di latar belakang memanfaatkan akselerasi GPU NVIDIA RTX 3060 dengan waktu inferensi ~12.5 detik per lembar formulir tanpa menyentuh database atau mengubah kode aplikasi inti.
