# Prompt untuk AI agent: perbarui & cek aplikasi di PC server

Salin seluruh isi blok di bawah ke AI agent (Claude Code, dsb.) yang berjalan
**di PC server**, dengan folder kerja = folder proyek Laravel di PC itu.

---

```text
Kamu bertugas memperbarui aplikasi Laravel "Downtime Mesin" di PC ini ke versi
terbaru dari GitHub, lalu menjalankan pengecekan otomatis dan mengumpulkan
hasilnya. Folder kerjamu adalah folder proyek Laravel. Baca dulu
deploy/PANDUAN-SERVER.md (setelah git pull), karena itu acuan langkahnya.

## Konteks penting
- Proyek di PC ini SUDAH tersambung ke database SQL Server lokal dan SUDAH
  sesuai kondisi database (pernah berjalan normal). File .env, koneksi database,
  dan konfigurasi lain di PC ini SUDAH BENAR. Jangan diubah.
- Database di PC ini adalah salinan lokal (bukan database produksi live), tetapi
  tugasmu tetap HANYA MEMBACA. Perintah `php artisan downtime:cek` memang hanya
  membaca.
- Pengembangan dilakukan di laptop lain. Di PC ini kamu TIDAK memperbaiki kode.
  Kalau ada error, kumpulkan buktinya untuk dilaporkan.

## Batasan (WAJIB)
1. JANGAN mengubah .env atau file konfigurasi apa pun. Bila ada peringatan
   konfigurasi (misalnya APP_DEBUG=true), cukup catat di laporan.
2. JANGAN mengubah database: jangan migrate, seed, atau query tulis
   (INSERT/UPDATE/DELETE). Jangan menyimpan, mengubah, atau menghapus data
   lewat aplikasi.
3. JANGAN mengubah kode di app/, routes/, resources/, config/, database/.
4. JANGAN commit, push, `git reset --hard`, atau `git stash drop`. Bila
   `git pull` gagal karena ada perubahan lokal, BERHENTI dan tampilkan
   `git status` ke pengguna. Jangan membuang perubahan itu tanpa izin.
5. Memasang program baru (Node.js, Composer, PHP extension) harus seizin
   pengguna. Tanya dulu.
6. Bila sebuah langkah gagal, coba perbaiki dalam batasan di atas (maksimal 3
   kali). Bila masih gagal, catat dan lanjutkan ke langkah yang tidak bergantung
   padanya.

## Langkah

### 0. Catat keadaan awal (hanya membaca)
- `git status`, `git branch --show-current`, `git log --oneline -3`
- `php -v`, `composer --version`, `node -v`, `npm -v` (catat bila belum terpasang)
- Tanyakan ke pengguna satu USERNAME akun petugas produksi (bukan AFSYAH)
  yang boleh dipakai untuk uji halaman. Bila pengguna tidak tahu, lanjutkan
  dengan AFSYAH saja dan catat.

### 1. Ambil kode terbaru
- `git fetch origin` lalu `git pull origin main`. Pastikan branch aktif adalah
  `main` (bila bukan, tanya pengguna dulu sebelum berpindah branch).
- Pastikan commit terbaru memuat "promosi draft ke aplikasi asli (tahap 1)",
  dan file deploy/PANDUAN-SERVER.md serta app/Console/Commands/CekDowntime.php ada.

### 2. Dependency
- `composer install --no-dev --optimize-autoloader`
- `npm ci` lalu `npm run build`. Bila Node.js tidak ada, tanya pengguna apakah
  boleh dipasang (Node 20 LTS atau lebih baru). Bila tidak boleh, catat
  "build aset dilewati": tampilan akan berantakan, tetapi pengecekan tetap jalan.

### 3. Bersihkan cache
- `php artisan optimize:clear`
- `php artisan config:cache`
- JANGAN jalankan `php artisan route:cache`.

### 4. Pengecekan otomatis (inti tugas)
Jalankan berurutan dan simpan output layarnya:
- `php artisan downtime:cek`
- `php artisan downtime:cek --user=<USERNAME_PETUGAS>` (bila ada)
- `php artisan downtime:cek --user=AFSYAH`
Setiap perintah membuat file storage/logs/cek-downtime-<tanggal-jam>.txt.
Perintah boleh selesai dengan baris [GAGAL]. Jangan dianggap tugas gagal: justru
itu yang perlu dilaporkan. Bila perintah berhenti di tengah (crash), catat
pesan errornya.

### 5. Kumpulkan log error aplikasi
- Ambil entri ERROR dari storage/logs/laravel.log yang muncul SELAMA langkah 4
  (lihat jam di awal tiap entri). Ambil maksimal 10 entri terakhir, masing-masing
  pesan utama + 15 baris pertama stack trace. Jangan tampilkan password atau
  isi .env.

### 6. Buat laporan
Buat file deploy/LAPORAN-SERVER-<YYYYMMDD-HHMM>.md (JANGAN di-commit) berisi:
1. Keadaan awal & versi (langkah 0) serta commit yang terpasang setelah pull.
2. Hasil langkah 1–3: berhasil / gagal / dilewati + pesan error bila ada.
3. ISI LENGKAP setiap file storage/logs/cek-downtime-*.txt yang dibuat di
   langkah 4. Salin apa adanya, jangan diringkas.
4. Entri error laravel.log dari langkah 5.
5. Ringkasan singkat: jumlah [GAGAL] dan [!] per perintah, serta daftar baris [GAGAL].

Terakhir, tampilkan isi laporan itu di layar dan beri tahu pengguna lokasi
filenya, supaya pengguna bisa menyalin seluruh isinya ke laptop pengembangan.
```

---

### Catatan untuk Anda (bukan bagian dari prompt)
- Setelah agent selesai, salin isi `deploy/LAPORAN-SERVER-*.md` (atau file-file
  `storage/logs/cek-downtime-*.txt`) ke chat di laptop.
- Uji tambah, ubah, dan hapus data lewat browser sebaiknya Anda lakukan sendiri.
  Agent sengaja dilarang menulis ke database.
