# Panduan Memperbarui Aplikasi di PC Server

Panduan ini dipakai setiap kali ada pembaruan dari laptop pengembangan. Semua
pengembangan dilakukan di laptop. Di PC server cukup menarik kode, menyesuaikan
dependency, lalu menjalankan pengecekan otomatis.

> Pemasangan AI Baca Kertas (E6) ada di panduan terpisah, `deploy/e6/PANDUAN.md`.
> E6 dikerjakan paling akhir.

## 1. Tarik kode terbaru

```powershell
cd <folder-proyek>
git pull origin main
```

## 2. Sesuaikan dependency

```powershell
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

`public/build` tidak ikut tersimpan di git, jadi `npm run build` wajib dijalankan
setelah setiap pull yang mengubah tampilan. Langkah ini butuh Node.js 20 atau lebih baru.

## 3. Atur `.env` (sekali saja, lalu cek lagi bila ada kunci baru)

```env
APP_ENV=production
APP_DEBUG=false          # pesan error lengkap tidak boleh terlihat pengguna

# Keamanan (perencanaan 2.15)
RUTE_UJI=false           # /test/* dan /preview/* (tanpa login) mati
DRAFT_ENABLED=false      # /draft (data contoh, tanpa login) mati

# Data besar
CACHE_STORE=file
DOWNTIME_CACHE_MENIT=10  # hasil query disimpan 10 menit; otomatis dibuang saat ada data baru
MESIN_AKTIF_HARI=0       # 0 = semua mesin MFRESMAS. Isi 90 bila MFRESMAS berisi banyak resource bukan mesin
PENGINGAT_INPUT_MENIT=60 # pengingat shift belum diinput muncul 60 menit setelah shift selesai
```

Kunci database (`DB_*`, `DB_DATABASE_SECONDARY`) tetap seperti yang sudah jalan selama ini.

Hapus `DB_OFFLINE_BYPASS` bila ada. Kunci itu hanya untuk laptop.

## 4. Bersihkan cache konfigurasi & tampilan

```powershell
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
```

Jangan jalankan `php artisan route:cache`, karena beberapa rute memakai closure.

Layanan AI Baca Kertas (E6) menyalakan ulang dirinya sendiri bila kodenya berubah
setelah `git pull`. Bila ragu, jalankan (sebagai Administrator):
`powershell -ExecutionPolicy Bypass -File deploy\e6\restart-layanan-e6.ps1`.

## 5. Jalankan pengecekan otomatis

```powershell
php artisan downtime:cek
php artisan downtime:cek --user=<username-petugas>
php artisan downtime:cek --user=AFSYAH
```

Perintah ini **hanya membaca** database. Yang diperiksa:

| Bagian | Isi |
|---|---|
| 1. Konfigurasi | APP_DEBUG, rute uji, draft, folder storage bisa ditulis |
| 2. Koneksi & tabel | Koneksi database, lalu setiap tabel & kolom yang dipakai (MFDOWNTIME, MFRESMAS, MFPROBLEM, MFPROBLEMD, TMMESINOP, ICITEM, TMUSER, TMUSERMENU, MFBOM) |
| 3. Volume data | Jumlah baris, rentang tanggal, per tahun, 30 hari terakhir, jumlah mesin |
| 4. Kecepatan | Waktu mengambil 30 & 365 hari data, memori, kecepatan dari cache |
| 5. Perhitungan | Ringkasan, analisis mesin/masalah/waktu/produk/operator, laporan, insight, pemeriksaan data, pemberitahuan |
| 6. Halaman (`--user`) | Membuka setiap halaman sebagai pengguna itu dan melaporkan kode HTTP |

Hasil ditampilkan di layar dan disimpan ke `storage/logs/cek-downtime-<tanggal>.txt`.
**Kirim isi file itu ke laptop pengembangan** (tempel di chat). Baris `[GAGAL]`
menyebut tabel, kolom, atau halaman yang bermasalah beserta pesan error-nya,
sehingga perbaikannya bisa dikerjakan di laptop.

## 6. Coba di browser

1. Buka `http://<alamat-server>/` dan login sebagai petugas. Anda akan diarahkan ke **Ringkasan**.
2. **Data Downtime → Tambah Data**: simpan 1 catatan uji, ubah, lalu hapus. Ketiganya tercatat di Audit Log.
3. Login sebagai **AFSYAH**: menu Operator & Admin muncul, dan tombol tambah/ubah/hapus tidak ada (admin hanya melihat).
4. Buka **Live** (`/live`) di layar TV ruang produksi (login dengan akun petugas).

## Bila ada masalah

| Gejala | Yang dilakukan |
|---|---|
| Halaman putih / error 500 | Lihat `storage/logs/laravel.log` (baris terakhir), kirim ke laptop |
| Tampilan berantakan / tanpa warna | `npm run build` belum dijalankan, atau masih ada cache lama: `php artisan optimize:clear` |
| Data lama tidak muncul di chart | Periode default 30 hari. Pilih periode lain di filter. Cek bagian 3 hasil `downtime:cek` |
| Halaman lambat | Kirim angka bagian 4 hasil `downtime:cek`. Untuk sementara, pakai periode lebih pendek |
| "Terlalu banyak percobaan login" | Batas 5 percobaan per menit (keamanan). Tunggu 1 menit |
