# Prompt untuk AI agent: pasang AI Baca Kertas (E6) di PC produksi

Salin seluruh isi blok di bawah ini ke AI agent (Claude Code, dsb.) yang berjalan
**di PC produksi**, dengan folder kerja = folder proyek Laravel. Jalankan agent
dari PowerShell **"Run as administrator"** supaya langkah Task Scheduler bisa
dikerjakan sendiri. Bila tidak, agent akan meminta Anda menjalankan perintah itu.

---

```text
Kamu bertugas memasang dan menguji modul "AI Baca Kertas" (Eksplorasi 6) di PC
produksi ini. Folder kerjamu adalah folder proyek Laravel. Semua bahan sudah ada
di deploy/e6/. Baca deploy/e6/PANDUAN.md sampai habis SEBELUM menjalankan apa pun,
karena panduan itu adalah acuan utama. Prompt ini hanya mengatur urutan dan batasan.

## Konteks
- Aplikasi Laravel (PHP) untuk pencatatan downtime produksi. Database SQL Server
  (MFDOWNTIME). Hanya diakses lewat intranet.
- Modul E6 = layanan Python (scripts/eksplorasi6/layanan_e6.py) yang membaca foto
  formulir memakai model TrOCR. Layanan ini mengambil antrian dari folder
  storage/app/scan-history/ (berbasis file, tanpa port, tanpa database).
- Spesifikasi PC target: Windows, Intel i7-4790, NVIDIA RTX 3060.
- Paket sudah diuji di laptop pengembang (CPU): pemasangan dari nol lolos,
  hasil 96/96 kotak. Yang BELUM pernah diuji: GPU (CUDA) dan Task Scheduler
  sebagai Administrator. Dua hal itu inti tugas ini.

## Batasan (WAJIB)
1. JANGAN mengubah database dalam bentuk apa pun: tabel, migrasi, `php artisan migrate`,
   seeder, query tulis.
2. JANGAN mengubah Python global, PATH sistem, atau aplikasi lain di PC ini. Semua
   paket masuk ke venv scripts/eksplorasi6/.venv lewat skrip yang tersedia.
3. JANGAN mengubah kode aplikasi (app/, routes/, resources/, config/, scripts/).
   Bila menurutmu ada bug, catat di laporan, jangan diperbaiki.
4. .env: buat cadangan dulu (.env.backup-e6-<tanggal>). Ubah HANYA kunci E6_*
   (E6_MODE, E6_DEVICE). Jangan menyentuh kunci lain. Jangan mengaktifkan
   DRAFT_ENABLED atau APP_DEBUG.
5. JANGAN menyalakan ulang, menghentikan, atau mengubah web server (Apache/Nginx/IIS/Laragon)
   maupun layanan Windows lain tanpa izin pengguna.
6. Memasang driver NVIDIA, Python 3.10, atau program lain di luar venv: TANYA
   pengguna dulu. Jelaskan apa yang mau dipasang dan alasannya.
7. Bila sebuah langkah gagal, berhenti di langkah itu. Diagnosis dan coba perbaiki
   dalam batasan di atas (maksimal 3 kali percobaan per masalah). Bila masih gagal,
   laporkan. Jangan melompat ke langkah berikutnya dan jangan mengakali uji
   (misalnya menurunkan syarat 96/96 atau memaksa device cpu tanpa izin).
8. Jangan commit atau push ke git.

## Langkah

### 0. Periksa keadaan (hanya membaca, belum mengubah apa pun)
Kumpulkan dan tampilkan:
- `git status` dan `git log --oneline -3` (pastikan ada commit "paket pemasangan PC
  produksi" dan folder deploy/e6/).
- Apakah PowerShell berjalan sebagai Administrator.
- `nvidia-smi`: nama GPU, versi driver, "CUDA Version" (harus >= 12.6).
- Python 3.10 64-bit tersedia? (`py -0`, `python --version`)
- Ada internet? (misalnya `Test-NetConnection pypi.org -Port 443`). Bila tidak ada,
  periksa apakah folder deploy/e6/paket/ sudah disalin (mode -Offline).
- Isi kunci E6_*, APP_ENV, APP_DEBUG di .env (JANGAN tampilkan password atau kunci lain).
- Web server apa yang dipakai dan berjalan sebagai akun apa (lihat Services /
  Task Manager). Ini perlu untuk pilihan akun Task Scheduler.
- Ruang kosong disk (butuh ±5 GB).
Bila ada syarat yang belum terpenuhi (driver lama, tidak ada Python 3.10, tidak ada
internet dan tidak ada paket offline), BERHENTI dan tanyakan ke pengguna.

### 1. Pasang lingkungan Python
- Online : `powershell -ExecutionPolicy Bypass -File deploy\e6\pasang-e6.ps1`
- Offline: `powershell -ExecutionPolicy Bypass -File deploy\e6\pasang-e6.ps1 -Offline`
Proses ini lama (unduhan ±3 GB). Jalankan dengan batas waktu panjang atau di latar belakang.
Syarat lolos: output cek_e6.py menunjukkan GPU RTX 3060 terdeteksi (CUDA 12.x),
"kotak Lost time sama dengan hasil uji — 96/96 kotak" (selisih 1–2 kotak di GPU
masih boleh, tapi laporkan), dan "SEMUA LOLOS".
Bila hasilnya "CUDA tidak terdeteksi", lihat tabel "Masalah umum" di PANDUAN.md.
Jangan beralih ke -Cpu tanpa izin pengguna.
Setelah lolos, jalankan juga
`scripts\eksplorasi6\.venv\Scripts\python.exe deploy\e6\cek_e6.py --device cuda`
dan catat waktu bacanya.

### 2. Atur .env
Cadangkan .env, lalu set `E6_MODE=python` dan `E6_DEVICE=cuda`.
Jalankan `php artisan config:clear`. Bila sebelumnya aplikasi memakai config cache
(ada bootstrap/cache/config.php), jalankan `php artisan config:cache` setelahnya.
Periksa dengan `php artisan config:show produksi.e6`: python harus menunjuk
scripts/eksplorasi6/.venv, trocr_model ke scripts/eksplorasi6/model/..., device cuda.

### 3. Daftarkan layanan ke Task Scheduler
Butuh Administrator. Bila kamu bukan Administrator, minta pengguna menjalankan
perintah ini sendiri di PowerShell "Run as administrator", lalu lanjutkan setelah
pengguna konfirmasi.
- Default (akun SYSTEM):
  `powershell -ExecutionPolicy Bypass -File deploy\e6\daftar-tugas-e6.ps1 -Device cuda`
- Bila web server berjalan sebagai akun pengguna biasa (bukan SYSTEM atau layanan), atau
  nanti muncul galat "Access denied" di storage\app\scan-history, daftar ulang dengan
  `-SebagaiSaya` dari akun yang sama dengan web server (tanyakan dulu ke pengguna).
Syarat lolos: pesan "Layanan HIDUP (pid ..., device cuda)". Pastikan juga di
storage\app\scan-history\layanan.json nilai idle_menit = 0 dan device = cuda.

### 4. Uji alur ujung-ke-ujung (tanpa web server, tanpa database)
`php deploy\e6\uji_alur.php`
Skrip ini membuat 1 scan uji dari fullimg, menunggu layanan memprosesnya,
membandingkan hasilnya dengan hasil acuan, lalu menghapus scan uji itu lagi.
Syarat lolos: "LOLOS", device cuda, dan baris layanan menunjukkan idle_menit 0
(artinya diproses oleh layanan Task Scheduler, bukan yang dinyalakan Laravel).
Jalankan 2 kali. Catat detik_proses keduanya. Yang kedua seharusnya lebih cepat
karena model sudah dimuat.

### 5. Uji ketahanan (singkat)
- Hentikan proses python layanan_e6 lewat Task Manager atau `Stop-Process`. Tunggu
  sampai ≤ 6 menit, lalu pastikan layanan hidup lagi sendiri (layanan.json
  diperbarui, idle_menit 0). Task Scheduler memeriksa setiap 5 menit.
- Bila pengguna mengizinkan restart PC: setelah restart, pastikan layanan hidup
  tanpa login. Bila tidak diizinkan, lewati dan tulis di laporan.

### 6. Uji dari browser (opsional, butuh pengguna)
Halaman /draft sengaja mati di produksi. Jangan diaktifkan. Bila halaman Tambah
Data asli sudah tersedia, minta pengguna mencoba kirim 1 foto dari HP lewat
intranet. Bila belum, lewati langkah ini.

## Laporan akhir
Tulis laporan ringkas dalam bahasa Indonesia, berisi:
1. Tabel per langkah (0–6): lolos / gagal / dilewati, beserta buktinya
   (potongan output penting).
2. Data lingkungan: GPU, driver, versi CUDA di nvidia-smi dan di torch, versi Python,
   mode online/offline, akun Task Scheduler.
3. Waktu baca per foto: cek_e6 (cuda) dan uji_alur run 1 & 2.
4. Semua perubahan yang kamu buat: file yang dibuat atau diubah, isi kunci .env yang
   diubah, lokasi cadangan .env, nama tugas Task Scheduler.
5. Masalah yang ditemui dan cara penyelesaiannya, serta hal yang masih
   menggantung atau perlu keputusan pengguna.
Simpan laporan ini juga ke deploy\e6\LAPORAN-PASANG-<tanggal>.md (file ini tidak
perlu di-commit).
```

---

### Catatan untuk Anda (bukan bagian dari prompt)

- Bila PC produksi **tanpa internet**, jalankan dulu di laptop:
  `powershell -ExecutionPolicy Bypass -File deploy\e6\siapkan-offline.ps1`.
  Lalu salin folder `deploy\e6\paket\` ke PC produksi sebelum agent dijalankan.
- Pastikan folder proyek di PC produksi sudah memuat commit terbaru (folder
  `deploy/e6/` dan `fullimg/` harus ada).
- Laporan `LAPORAN-PASANG-*.md` dari agent bisa Anda tempel ke sini untuk kita bahas.
