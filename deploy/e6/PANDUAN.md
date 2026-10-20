# Panduan Pasang AI Baca Kertas (Eksplorasi 6) di PC Produksi

Target: PC Windows (i7-4790, RTX 3060) yang menjalankan aplikasi Laravel ini dan
hanya diakses lewat intranet. Python global tidak diubah. Semua yang dipasang ada
di dalam folder proyek:

| Isi | Lokasi |
|---|---|
| venv Python khusus E6 | `scripts\eksplorasi6\.venv\` |
| Model TrOCR (±250 MB) | `scripts\eksplorasi6\model\trocr-small-handwritten\` |
| Antrian dan riwayat scan | `storage\app\scan-history\` |
| Log layanan | `storage\logs\layanan-e6.log` |

Isi folder `deploy\e6\`:

| File | Fungsi |
|---|---|
| `requirements-e6.txt` | Daftar paket Python dengan versi terkunci (versi yang sudah diuji) |
| `pasang-e6.ps1` | Membuat venv, memasang paket, menyalin atau mengunduh model, lalu menjalankan uji |
| `siapkan-offline.ps1` | Mengunduh semua bahan pemasangan ke `deploy\e6\paket\` untuk PC tanpa internet |
| `cek_e6.py` | Uji lingkungan: versi paket, GPU, model, lalu baca 1 foto contoh (harus 96/96 kotak) |
| `jalankan-layanan-e6.cmd` | Menjalankan layanan E6 yang selalu hidup |
| `daftar-tugas-e6.ps1` | Mendaftarkan `jalankan-layanan-e6.cmd` ke Task Scheduler |
| `uji_alur.php` | Uji ujung ke ujung lewat Laravel dan layanan, tanpa web server dan database |
| `PROMPT-AGENT.md` | Prompt siap pakai untuk AI agent yang mengerjakan pemasangan ini secara otomatis |

---

## 0. Persiapan

1. **Driver NVIDIA** untuk RTX 3060, versi terbaru (minimal 528). Cek dengan
   `nvidia-smi` di Command Prompt. Baris `CUDA Version` harus menunjukkan 12.6 atau
   lebih tinggi. CUDA Toolkit **tidak perlu** dipasang, karena sudah ikut di dalam torch.
2. **Python 3.10.11 64-bit** dari <https://www.python.org/downloads/release/python-31011/>
   (atau `paket\python-3.10.11-amd64.exe` dari paket offline). Saat memasang,
   centang **Add python.exe to PATH**. Versi lain belum diuji.
3. Program Tesseract-OCR **tidak perlu** dipasang. Yang diperlukan hanya paket
   `pytesseract`, dan itu sudah masuk daftar.
4. Aplikasi Laravel sudah berjalan seperti biasa (PHP, web server, `.env`).

## 1. Pasang lingkungan Python

Buka PowerShell di folder proyek Laravel.

**A. PC produksi punya internet**

```powershell
powershell -ExecutionPolicy Bypass -File deploy\e6\pasang-e6.ps1
```

Unduhannya ±3 GB, karena torch versi CUDA saja ±2,5 GB.

**B. PC produksi tanpa internet**

1. Di komputer yang punya internet (misalnya laptop):
   ```powershell
   powershell -ExecutionPolicy Bypass -File deploy\e6\siapkan-offline.ps1
   ```
2. Salin folder `deploy\e6\paket\` (±3 GB) ke lokasi yang sama di PC produksi.
3. Di PC produksi:
   ```powershell
   powershell -ExecutionPolicy Bypass -File deploy\e6\pasang-e6.ps1 -Offline
   ```

Kedua cara diakhiri dengan `cek_e6.py`. Pemasangan dianggap berhasil bila outputnya
seperti ini:

```
2. GPU
  CUDA 12.6, GPU: NVIDIA GeForce RTX 3060
...
  [OK ] kotak Lost time sama dengan hasil uji — 96/96 kotak
  [OK ] header sama dengan hasil uji
SEMUA LOLOS
```

Pilihan tambahan: `-Cpu` untuk PC tanpa GPU NVIDIA, `-Ulang` untuk membuat ulang
venv, dan `-Python "C:\...\python.exe"` untuk memakai Python tertentu.

> Peringatan `Some weights ... encoder.pooler ... newly initialized` dan
> `Using a slow image processor` itu **normal**, karena bagian tersebut memang
> tidak dipakai TrOCR. Yang perlu diwaspadai adalah hasil uji yang tidak 96/96.
> Di GPU, selisih 1–2 kotak ragu masih wajar.

## 2. Atur `.env`

```env
E6_MODE=python
E6_DEVICE=cuda
```

Lokasi Python dan model tidak perlu diisi, karena Laravel otomatis memakai
`scripts\eksplorasi6\.venv` dan `scripts\eksplorasi6\model\...`. Setelah mengubah
`.env`, jalankan `php artisan config:clear`. Jika memakai `config:cache`,
jalankan ulang perintah itu.

## 3. Jalankan layanan selalu hidup (Task Scheduler)

Buka PowerShell **Run as Administrator** di folder proyek:

```powershell
powershell -ExecutionPolicy Bypass -File deploy\e6\daftar-tugas-e6.ps1
```

Skrip ini mendaftarkan tugas **"AI Baca Kertas E6"** yang:

- menyala saat komputer hidup, tanpa perlu login, memakai akun SYSTEM;
- diperiksa tiap 5 menit. Bila layanan mati, tugas menyalakannya lagi. Bila masih hidup, pemicu diabaikan;
- berjalan dengan `--idle-menit 0` (tidak pernah berhenti sendiri) dan `--device cuda`;
- memuat model satu kali saat foto pertama masuk (±6 detik), lalu model tetap di memori GPU.

Skrip ditutup dengan pesan `Layanan HIDUP (pid ..., device cuda)`.

Tanpa Task Scheduler pun fitur ini tetap berjalan. Laravel menyalakan layanan sendiri
saat ada foto masuk, dan layanan itu berhenti setelah 30 menit menganggur. Bedanya,
foto pertama setelah layanan mati jadi lebih lambat karena model harus dimuat ulang.

Pilihan lain:
- `-SebagaiSaya`: memakai akun Windows Anda, bukan SYSTEM. Pakai ini bila muncul
  galat izin (*Access denied*) di `storage\app\scan-history`, misalnya karena web
  server berjalan dengan akun lain.
- `-Device cpu`: untuk PC tanpa GPU.
- `-Hapus`: menghapus tugas.

## 4. Uji dari aplikasi

Uji tanpa browser, tanpa halaman /draft, dan tanpa database:

```powershell
php deploy\e6\uji_alur.php
```

Skrip ini membuat 1 scan uji, menunggu layanan memprosesnya, mencocokkan hasilnya
dengan hasil acuan, lalu menghapus scan uji itu lagi. Uji dianggap lolos bila outputnya
`LOLOS` dan `idle_menit 0`, yang berarti foto diproses oleh layanan Task Scheduler.

Uji dari browser:

1. Login sebagai petugas, buka **Tambah Data → Foto formulir** (`http://<alamat-server>/tambah/foto`).
2. Kirim foto dari folder `fullimg`. Halaman Layanan AI harus menunjukkan
   **Siap · CUDA**, dan pemberitahuan **"Selesai diproses"** muncul beberapa detik kemudian.
3. Coba juga dari HP lewat Wi-Fi kantor dengan tombol **Kamera HP**.

> Tombol **Kamera komputer** (webcam di browser) hanya aktif di `localhost` atau
> HTTPS. Ini aturan keamanan browser. Bila diakses lewat `http://192.168.x.x`,
> pakai **Kamera HP** atau **Pilih file**. Bila webcam di PC lain wajib dipakai,
> server perlu dipasangi HTTPS (sertifikat internal).

## 5. Perawatan

| Kegiatan | Cara |
|---|---|
| Lihat status layanan | `storage\app\scan-history\layanan.json` (detak, device, jumlah diproses) |
| Lihat log | `storage\logs\layanan-e6.log` |
| Menyalakan ulang layanan | `powershell -ExecutionPolicy Bypass -File deploy\e6\restart-layanan-e6.ps1` (Run as administrator). Jangan hanya "End" di Task Scheduler, karena proses `python.exe` lama bisa tetap hidup |
| Bersihkan foto lama | Otomatis lewat `php artisan scan:bersihkan` (terjadwal 02:00). Syaratnya, scheduler Laravel (`php artisan schedule:run` tiap menit) harus aktif di Task Scheduler. Foto disimpan 90 hari dan data 365 hari, bisa diubah lewat `E6_RETENSI_*` |
| Memperbarui kode E6 | Cukup `git pull`. Sejak 20-10-2026 layanan berhenti sendiri bila file kodenya berubah, lalu dinyalakan ulang dengan kode baru (Task Scheduler ≤5 menit, atau Laravel saat ada foto masuk). venv tidak perlu dipasang ulang |
| Memindah folder proyek | Jalankan ulang `pasang-e6.ps1 -Ulang` (venv menyimpan path absolut), lalu `daftar-tugas-e6.ps1` |

## 6. Masalah umum

| Gejala | Penyebab dan solusi |
|---|---|
| `cek_e6.py`: `CUDA tidak terdeteksi` | Driver NVIDIA terlalu lama, atau torch yang terpasang versi CPU. Perbarui driver, lalu jalankan `pasang-e6.ps1 -Ulang` |
| `transformers 4.x` GAGAL | Ada yang memasang transformers 5.x ke venv. Jalankan `pasang-e6.ps1 -Ulang` |
| Hasil uji jauh dari 96/96 | Biasanya versi paket berbeda dari `requirements-e6.txt`. Buat ulang venv. Bila masih gagal, cek apakah file `scripts\eksplorasi6\reference\` utuh |
| Halaman: "Belum berjalan" terus | Lihat `storage\logs\layanan-e6.log` dan `storage\logs\laravel.log` (cari `LayananE6`). Pastikan akun web server boleh menjalankan `powershell` dan `python.exe` di venv |
| Status scan "Gagal diproses" | Klik "Detail teknis" di halaman tinjau. Galat memuat model berarti lingkungan belum lengkap, jadi jalankan `cek_e6.py` |
| Semua foto "Perlu foto ulang" | Formulir tidak dikenali (cara memotret kurang baik, atau formulirnya berbeda versi). Ikuti tips di halaman Tambah Data |
| `Access denied` di `scan-history` | Akun layanan (SYSTEM) dan akun web server berbeda. Daftar ulang dengan `daftar-tugas-e6.ps1 -SebagaiSaya`, memakai akun yang sama dengan web server |
