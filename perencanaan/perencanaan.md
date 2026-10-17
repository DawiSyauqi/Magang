# Perencanaan Pengembangan Sistem — Downtime Mesin + AI Baca Kertas

Disusun 16 Oktober 2026. Dokumen ini masih rencana (belum ada kode yang diubah). Keputusan K1–K6 sudah dijawab pada 16 Oktober 2026 dan dimasukkan ke rencana ini (ringkasan di bagian 6). K7 dibahas nanti.

**Prinsip yang berlaku (hasil keputusan):**

- File lama **boleh diubah** untuk pengembangan ini (K1).
- **Struktur database tidak diubah**: tidak ada tabel, kolom, atau index baru (K3). Semua yang butuh penyimpanan baru memakai **file di `storage/`** atau **file config**. Fitur yang benar-benar butuh tabel ditandai *"ditunda (butuh DB)"*.
- Analitik dilihat oleh **admin dan petugas produksi** (K2). Halaman Operator **hanya admin** (K5).
- Shift **480 menit**: shift 1 = 07–15, shift 2 = 15–23, shift 3 = 23–07 (melewati tengah malam) (K4).
- Desain mengikuti rekomendasi bagian 3.1 dulu, dan bisa berubah nanti (K6).

Ada tiga hal yang direncanakan:

1. **Sistem informasi**: halaman analitik berisi KPI, chart, dan laporan dari data downtime yang sudah masuk.
2. **Desain tampilan baru**: tampilan yang lebih menarik dan konsisten, bisa mengikuti referensi desain dari Anda.
3. **Implementasi Eksplorasi 6**: satu foto seluruh halaman menggantikan Section 1 + Section 2.

Di bagian akhir ada saran fitur tambahan, urutan pengerjaan, dan keputusan yang perlu Anda ambil.

---

## 0. Kondisi saat ini (titik awal)

| Bagian                     | Keadaan sekarang                                                                                                                                         |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Dashboard (`/dashboard`) | Tabel riwayat (No_Trs, tanggal, shift, jam mulai/selesai, total menit, mesin), filter per hari / per bulan, paginasi 10, tambah/edit/hapus               |
| Input                      | Manual (modal form) dan AI Baca Kertas (`/paper-scan`, 2 foto + 3 klik titik, Ollama)                                                                  |
| Admin (AFSYAH)             | Kelola alias mesin, reset password pengguna                                                                                                              |
| Tampilan                   | Bootstrap 5.3 + SCSS sendiri, gaya "Monochrome Minimal + Glass & Subtle Glow" (`resources/css/_tokens.scss`), ada dark mode toggle, sidebar "OptiGear" |
| Chart                      | Belum ada library chart sama sekali                                                                                                                      |

**Data yang tersedia untuk dianalisis**, dari tabel transaksi `MFDOWNTIME` (model `PerformaMesin`):

| Kolom                                          | Isi                                          | Bisa dipakai untuk                            |
| ---------------------------------------------- | -------------------------------------------- | --------------------------------------------- |
| `Tgl_Trs`                                    | tanggal produksi                             | tren harian/mingguan/bulanan, kalender        |
| `ShiftCode`                                  | 1 / 2 / 3                                    | perbandingan shift                            |
| `Time_Start`, `Time_End`, `Time_Total`   | jam & durasi (menit)                         | total downtime, jam rawan, durasi rata-rata   |
| `MesinCode` (→ `MFRESMAS`)                | kode & nama mesin                            | ranking mesin, profil mesin                   |
| `NIK` (→ `TMMESINOP`)                     | operator                                     | analisis per operator                         |
| `ProblemCode` (→ `MFPROBLEM`)             | kategori masalah D01–D07                    | Pareto masalah, komposisi                     |
| `Problem_Desc`                               | deskripsi detail masalah (→ `MFPROBLEMD`) | Pareto detail masalah                         |
| `ITEMNO` (→ `ICITEM`)                     | produk yang sedang dibuat                    | downtime per produk                           |
| `Speed_Mesin`                                | kecepatan mesin (m/mnt)                      | sebaran speed, hubungan speed dengan downtime |
| `CrtId`, `CrtDate`, `UpdId`, `UpdDate` | siapa & kapan input/ubah                     | aktivitas input, keterlambatan input          |

**Batasan data yang perlu diingat:**

- Kategori **0 (Running Well)** dan **8 (Istirahat)** tidak disimpan ke database (di-skip `GridTimeMerger`). Artinya data hanya berisi waktu bermasalah, tidak ada catatan waktu jalan normal. KPI seperti *availability* harus memakai **asumsi waktu shift** (lihat 1.2).
- Tidak ada data jumlah produksi (output meter/kg), jadi OEE penuh (Availability × Performance × Quality) **tidak bisa** dihitung. Yang bisa dihitung hanya pendekatan Availability.
- Tidak ada kolom yang membedakan data input manual dan data hasil AI. Karena database tidak diubah (K3), sumber input dicatat di **file log scan** (`storage/app/scan-history/`, lihat 2.6). No_Trs yang tersimpan dari AI dicocokkan lewat file tersebut.
- Driver yang sekarang dipakai (`.env`): `CACHE_STORE=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync`. Cache sudah berbasis file, sehingga tidak butuh tabel.

---

## 1. Fitur Sistem Informasi (analitik & visualisasi)

### 1.1 Struktur menu baru

```
Sidebar
├── Ringkasan            (halaman utama baru: KPI + chart inti)
├── Analisis
│   ├── Mesin            (ranking + profil per mesin)
│   ├── Masalah          (Pareto kategori & detail)
│   ├── Waktu & Shift    (heatmap jam, tren, kalender)
│   ├── Operator         (KHUSUS ADMIN)
│   └── Produk / Item
├── Data Downtime        (tabel yang sekarang, + filter lengkap + export)
├── Tambah Data          (paper-scan: AI Baca Kertas + manual)   — khusus petugas
├── Laporan              (laporan harian/shift/bulanan siap cetak/PDF/Excel)
└── Akun                 (password saya, logout)

Admin (AFSYAH): Alias Mesin, Password Pengguna, Statistik AI Baca Kertas, Audit Log, Pengaturan (target per mesin, daftar mesin aktif)
```

**Hak akses per halaman (K2, K5):**

| Halaman | Petugas produksi | Admin (AFSYAH) | Catatan |
|---|---|---|---|
| Ringkasan, Analisis Mesin/Masalah/Waktu & Shift/Produk | ✅ | ✅ | |
| Analisis Operator | ❌ | ✅ | data per orang (K5) |
| Data Downtime: lihat + export | ✅ | ✅ | |
| Data Downtime: tambah/ubah/hapus, Tambah Data (scan) | ✅ | ❌ | admin tetap tidak menginput produksi (aturan yang sudah ada) |
| Laporan | ✅ | ✅ | |
| Menu admin (alias, password, statistik AI, audit log, pengaturan) | ❌ | ✅ | |

Dampak teknis: middleware `production` yang sekarang mengalihkan admin dari `/dashboard` tetap dipakai **hanya untuk rute input** (tambah/ubah/hapus, paper-scan). Rute analitik, laporan, dan tabel baca-saja memakai grup baru `auth` + `menu.access` (admin sudah di-bypass di `EnsureMenuAccess`). Halaman Operator memakai middleware `admin`. Setelah login, admin diarahkan ke Ringkasan, bukan lagi ke Alias Mesin.

**Filter global** dipakai bersama di semua halaman analitik dan diingat di URL (query string), sehingga tampilan bisa dibagikan atau di-bookmark:

- Rentang tanggal (preset: hari ini, kemarin, 7 hari, bulan ini, bulan lalu, kustom)
- Mesin (multi-pilih), Shift (1/2/3), Kategori masalah, Operator, Item
- Pembanding periode: "dibanding periode sebelumnya" (mis. bulan ini vs bulan lalu)

### 1.2 Definisi KPI (dipakai di semua halaman)

| KPI                                     | Rumus                                                               | Catatan                                                                                                                                                          |
| --------------------------------------- | ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Total downtime                          | Σ `Time_Total` (menit → ditampilkan jam:menit)                  |                                                                                                                                                                  |
| Jumlah kejadian                         | COUNT baris                                                         | 1 baris = 1 kejadian downtime                                                                                                                                    |
| Rata-rata durasi per kejadian (≈ MTTR) | total downtime / jumlah kejadian                                    | MTTR versi sederhana (waktu henti rata-rata)                                                                                                                     |
| Downtime % (≈ 1 − Availability)       | total downtime / waktu tersedia                                     | **Waktu tersedia = jumlah mesin di `MFRESMAS` × 3 shift × 480 menit × jumlah hari** (K4, 6.2: semua mesin dihitung). Karena mesin yang tidak dijadwalkan jalan ikut terhitung, angkanya cenderung kecil. Sebagai pembanding juga ditampilkan versi "per shift yang punya data" (mesin–tanggal–shift dengan minimal 1 kejadian) |
| MTBF (perkiraan)                        | (waktu tersedia − downtime) / jumlah kejadian                      | Hanya perkiraan karena data jalan normal tidak disimpan                                                                                                          |
| Mesin terburuk                          | mesin dengan downtime terbesar di periode                           |                                                                                                                                                                  |
| Masalah dominan                         | kategori dengan downtime terbesar                                   |                                                                                                                                                                  |
| Perubahan vs periode lalu               | (nilai sekarang − nilai lalu) / nilai lalu                         | ditampilkan ▲/▼ berwarna                                                                                                                                       |
| Kelengkapan input                       | (mesin × shift yang punya data) / (mesin × shift yang seharusnya) | Mendeteksi shift yang lupa diinput. "Seharusnya" = semua mesin `MFRESMAS` × 3 shift (6.2). Karena data hanya mencatat downtime, shift tanpa data bisa berarti "tidak ada masalah" atau "lupa input". Oleh karena itu ditampilkan sebagai **status (ada data / belum ada data), bukan nilai benar/salah** |

**Jadwal shift (K4)** dipakai di heatmap jam, Gantt, dan validasi:

| Shift | Jam | Catatan |
|---|---|---|
| 1 | 07:00–15:00 | |
| 2 | 15:00–23:00 | |
| 3 | 23:00–07:00 | mencakup 2 tanggal (1 jam di hari pertama + 7 jam di hari berikutnya) |

**Aturan tanggal untuk shift 3 (6.2):** `Tgl_Trs` **tidak selalu** tanggal mulai shift. Isinya tergantung tanggal yang diinput petugas. Karena itu analitik **tidak menebak atau memindahkan tanggal**:

- Rekap per tanggal, kalender, dan tren harian memakai `Tgl_Trs` **apa adanya**.
- Heatmap jam dan distribusi jam memakai **jam** dari `Time_Start` (selalu benar, terlepas dari tanggalnya).
- Hari dalam minggu (Sen–Min) di heatmap memakai `Tgl_Trs`.
- Timeline/Gantt harian memakai sumbu **07:00 → 07:00** (urutan shift 1 → 2 → 3), bukan 00:00 → 24:00, sehingga shift 3 tampil utuh di ujung kanan. Kejadian shift 3 jam 00:00–07:00 diletakkan di bagian "setelah tengah malam" pada tanggal `Tgl_Trs`-nya.
- Kelengkapan input shift 3 untuk tanggal D dianggap "ada data" bila ada kejadian shift 3 dengan `Tgl_Trs` = D. Keterangan di UI: *"shift 3 dicatat sesuai tanggal input"*.
- Catatan teknis: `Time_Start` disusun dari `Tgl_Trs` + jam (`DashboardController::hitungWaktu`), jadi tanggal di `Time_Start` mengikuti `Tgl_Trs`. Hanya bagian jamnya yang dipakai untuk analisis jam.

Jadwal ini disimpan sebagai konstanta di `config/produksi.php` (bukan di database), sehingga mudah diubah bila jadwal berganti.

### 1.3 Halaman "Ringkasan" (halaman utama baru)

**Baris KPI** (6 kartu, masing-masing dengan sparkline 14 hari dan ▲/▼ dibanding periode lalu):

1. Total downtime periode ini
2. Jumlah kejadian
3. Rata-rata durasi per kejadian
4. Downtime % / perkiraan availability
5. Mesin dengan downtime tertinggi (nama + menit)
6. Masalah dominan (kategori + % kontribusi)

**Chart:**

| #  | Chart                             | Jenis                                               | Kegunaan                                         |
| -- | --------------------------------- | --------------------------------------------------- | ------------------------------------------------ |
| R1 | Tren downtime harian              | area/line, garis rata-rata (garis target hanya muncul bila admin mengisi target, lihat 2.2) | Melihat hari yang naik tajam                     |
| R2 | Pareto kategori masalah           | bar + garis kumulatif % (80/20)                     | Masalah mana yang perlu diprioritaskan           |
| R3 | Top 10 mesin berdasarkan downtime | bar horizontal, ditumpuk per kategori               | Mesin mana dan karena apa                        |
| R4 | Komposisi downtime per shift      | donut / bar ditumpuk                                | Shift mana yang paling bermasalah                |
| R5 | Heatmap jam × hari dalam minggu  | heatmap                                             | Jam rawan (mis. awal shift, menjelang istirahat) |
| R6 | Kejadian terbaru                  | daftar 10 baris + tautan ke detail                  | Pengganti tabel sebagai info cepat               |
| R7 | Status input hari ini             | grid mesin × shift (hijau = ada data, abu = belum) | Petugas tahu shift/mesin mana yang belum diinput |

### 1.4 Halaman "Analisis Mesin"

- **M1** Tabel ranking mesin: total downtime, jumlah kejadian, rata-rata durasi, downtime %, kategori dominan, tren (sparkline), perubahan vs periode lalu. Bisa diurutkan, dan baris bisa diklik untuk membuka profil.
- **M2** Treemap downtime per mesin, dikelompokkan per jenis/line mesin (dari awalan kode, mis. AN, DD, ML) bila pola kode mendukung.
- **M3** Scatter "frekuensi vs durasi": sumbu X jumlah kejadian, sumbu Y rata-rata durasi, ukuran titik = total downtime. Kuadran kanan-atas = mesin kritis (sering rusak dan lama).
- **Profil satu mesin** (`/analisis/mesin/{kode}`):
  - KPI mesin (seperti Ringkasan, khusus mesin ini)
  - Tren downtime harian/mingguan
  - Pareto masalah khusus mesin ini
  - **Timeline / Gantt per hari**: bar per shift, potongan berwarna sesuai kategori, menunjukkan kapan mesin berhenti dalam 24 jam. Ini paling mirip dengan formulir kertas Lost time, sehingga mudah dipahami petugas.
  - Item yang paling sering diproses saat downtime
  - Operator yang bertugas
  - Riwayat kejadian (tabel)
- **M4** Perbandingan 2–4 mesin berdampingan (tren dan Pareto).

### 1.5 Halaman "Analisis Masalah"

- **P1** Pareto kategori (D01–D07) dan **drill-down** ke Pareto detail masalah (`Problem_Desc` / `MFPROBLEMD`)
- **P2** Sunburst / treemap kategori → detail
- **P3** Tren per kategori (line bertumpuk 100% atau multi-line), untuk melihat masalah yang semakin sering
- **P4** Matriks mesin × kategori (heatmap): mesin mana yang punya masalah apa
- **P5** Distribusi durasi per kategori (box plot / histogram): masalah yang biasanya singkat vs yang lama
- **P6** "Masalah berulang": mesin dengan masalah detail yang sama ≥ N kali dalam 7 hari, sebagai indikasi perbaikan belum tuntas

### 1.6 Halaman "Waktu & Shift"

- **W1** Kalender heatmap (gaya kontribusi GitHub): satu kotak per hari, warna sesuai total downtime; klik hari untuk membuka detail
- **W2** Heatmap jam (00–23) × hari (Sen–Min)
- **W3** Perbandingan shift 1/2/3: total, rata-rata per shift, kategori dominan per shift
- **W4** Tren mingguan dan bulanan (bar) dengan pembanding tahun/bulan sebelumnya
- **W5** Distribusi jam mulai downtime dalam shift (menit ke-0 s/d 480), untuk melihat apakah masalah sering muncul di awal shift (setting/pemanasan) atau di akhir
- **W6** "Timeline semua mesin dalam 1 hari": Gantt besar, baris = mesin, sumbu X = 24 jam. Cocok untuk rapat pagi.

### 1.7 Halaman "Operator"

- **O1** Downtime per operator (total, jumlah kejadian, rata-rata durasi), dinormalisasi per shift bertugas supaya adil
- **O2** Kategori masalah per operator
- **O3** Jumlah input data per petugas (`CrtId`) dan keterlambatan input (`CrtDate` − `Tgl_Trs`)
- 🔒 **Hanya admin (AFSYAH)** (K5): rute dilindungi middleware `admin` dan menu tidak muncul untuk petugas. Halaman diberi label "bukan penilaian kinerja". Filter Operator di filter global juga hanya tampil untuk admin.

### 1.8 Halaman "Produk / Item"

- **I1** Downtime per item (`ITEMNO` → `ICITEM.DESC`): produk yang paling sering bermasalah
- **I2** Matriks item × kategori masalah
- **I3** Sebaran `Speed_Mesin` per mesin/item (box plot), dan downtime rata-rata pada rentang speed tertentu (apakah speed tinggi diikuti lebih banyak masalah)

### 1.9 Halaman "Data Downtime" (tabel yang sekarang, ditingkatkan)

- Filter lengkap (filter global + pencarian No_Trs/deskripsi)
- Kolom tambahan: operator (kolom ini hanya untuk admin), kategori/detail masalah, item, speed, sumber input (manual/AI, dari file log scan 2.6)
- Pengurutan per kolom, pilihan jumlah baris (10/25/50/100)
- Klik baris untuk membuka panel detail (semua field + `CrtId/CrtDate/UpdId/UpdDate` + foto formulir bila dari AI)
- **Export Excel / CSV** sesuai filter
- Hapus/ubah tetap seperti sekarang (khusus petugas), ditambah konfirmasi dan audit log berbasis file (2.5). Admin melihat tabel dalam mode baca-saja.

### 1.10 Halaman "Laporan"

- **Laporan harian/shift** siap cetak (1 halaman A4): KPI, timeline semua mesin, top masalah, daftar kejadian. Tombol **PDF** dan **cetak**.
- **Laporan bulanan**: tren, Pareto, ranking mesin, perbandingan dengan bulan lalu, catatan otomatis (mis. "Downtime naik 18% terutama di mesin DD16 karena kategori D03").
- Export Excel dengan beberapa sheet (ringkasan, per mesin, per masalah, data mentah).

### 1.11 Teknis pengerjaan analitik

- **Library chart:** rekomendasi **Apache ECharts** (dipasang lewat npm, dibundel Vite). Library ini sudah mendukung semua jenis chart di atas (Pareto, heatmap, kalender, treemap, sunburst, Gantt lewat custom series, box plot), gratis, mendukung dark mode lewat tema, dan ringan bila di-import per modul. Alternatif yang lebih sederhana: ApexCharts, tetapi tidak punya kalender/sunburst/Gantt bawaan.
- **Arsitektur:** halaman Blade + endpoint JSON (`/api/analitik/...`) per chart. Halaman tampil cepat dan chart dimuat bertahap (skeleton loading).
- **Query:** agregasi dilakukan di SQL Server (`GROUP BY`, `DATEPART(hour, Time_Start)`, `SUM(Time_Total)`), bukan di PHP. Logika ditaruh di service baru (mis. `App\Services\Analytics\DowntimeAnalytics`) supaya bisa diuji.
- **Performa:** cache hasil agregasi (`Cache::remember`, 5–10 menit, store `file` yang sudah aktif, dihapus saat ada data baru). Index tidak ditambah (K3). Query selalu dibatasi rentang tanggal (default 30 hari) dan memakai kolom `Tgl_Trs` langsung tanpa fungsi di klausa `WHERE`, supaya index yang mungkin sudah ada tetap terpakai.
- **Pengaturan tanpa database (K3):**
  - Nilai tetap (jadwal shift, 480 menit, palet warna kategori) disimpan di `config/produksi.php`.
  - Nilai yang diubah admin lewat UI (target downtime opsional per mesin/shift; belum ada target resmi, 6.2) disimpan di `storage/app/pengaturan.json` lewat satu service kecil (`PengaturanStore`) yang membaca/menulis dengan file lock. Pola ini sama dengan `MesinAliasStore` yang sudah ada.
  - Kekurangan: tidak ada riwayat perubahan, dan file harus ikut di-backup. Bila nanti database boleh diubah, isinya bisa dipindah ke tabel tanpa mengubah tampilan.

---

## 2. Saran fitur tambahan (selain chart)

| #    | Fitur                                                                                                                               | Manfaat                                                                                                      | Prioritas                   |
| ---- | ----------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ | --------------------------- |
| 2.1  | ~~**Peran Supervisor/Manajer**~~: **ditunda**. Sesuai K2, analitik cukup untuk admin + petugas. Bisa ditambah nanti tanpa mengubah halaman analitik, cukup dengan aturan akses baru | Analitik ditujukan ke atasan                                                                                 | Ditunda                     |
| 2.2  | **Target & ambang batas** per mesin (mis. maks 60 menit/shift) + penanda merah bila terlewati. Belum ada target resmi (6.2), jadi fitur ini **opsional**: kosong secara default, admin bisa mengisinya nanti di `storage/app/pengaturan.json`. Sebelum diisi, acuan "baik/buruk" memakai **perbandingan dengan periode sebelumnya** dan **rata-rata 30 hari** | KPI punya acuan "baik/buruk"                                                                                 | Sedang                      |
| 2.3  | **Status kelengkapan input** + pengingat shift yang belum diinput                                                             | Data analitik jadi lengkap                                                                                   | Tinggi                      |
| 2.4  | **Export Excel/PDF** (tabel & laporan)                                                                                        | Kebutuhan umum di pabrik untuk rapat                                                                         | Tinggi                      |
| 2.5  | **Audit log** (siapa tambah/ubah/hapus apa, kapan, nilai lama → baru). **Tanpa DB:** channel log Laravel khusus `storage/logs/audit-YYYY-MM-DD.log` (format JSON per baris), dibaca oleh halaman admin dengan filter tanggal/pengguna | Akuntabilitas, terutama karena admin bisa reset password                                                     | Sedang                      |
| 2.6  | **Riwayat scan**: simpan foto formulir + hasil AI + koreksi petugas. **Tanpa DB:** folder `storage/app/scan-history/YYYY-MM/<token>/` berisi `foto.jpg`, `hasil_ai.json`, `final.json` (setelah koreksi, termasuk No_Trs yang tersimpan). Perlu aturan pembersihan (mis. foto > 6 bulan dihapus, JSON disimpan) | Bukti fisik, dan menjadi **dataset** untuk melatih/menguji model berikutnya (saran pertama di laporan) | Sedang–Tinggi              |
| 2.7  | **Validasi otomatis**: jam tumpang tindih di mesin yang sama, total per shift > durasi shift, tanggal di masa depan, duplikat | Mencegah data salah masuk ke chart                                                                           | Tinggi                      |
| 2.8  | **Notifikasi** bila downtime mesin melewati ambang atau masalah berulang. Versi tanpa DB: banner/lonceng in-app yang dihitung langsung saat halaman dibuka. Notifikasi Laravel ber-database dan email/Telegram/WA **ditunda** | Respons lebih cepat                                                                                          | Rendah–Sedang              |
| 2.9  | **Statistik AI Baca Kertas** (admin): jumlah scan, % kotak ragu, % dikoreksi petugas, waktu proses, foto needs_retake. Dihitung dari file riwayat scan (2.6), dengan ringkasan yang di-cache | Mengukur kualitas AI di lapangan                                                                             | Sedang                      |
| 2.10 | **Kelola master** (kategori masalah, data mesin): **ditunda (butuh DB)**. Daftar mesin aktif tidak diperlukan karena semua mesin `MFRESMAS` dihitung (6.2) | Saat ini master hanya dibaca                                                                                 | Ditunda                     |
| 2.11 | **Mode layar TV / andon** (`/tv`): Ringkasan hari ini, auto-refresh, huruf besar                                            | Dipasang di ruang produksi                                                                                   | Rendah                      |
| 2.12 | **PWA / ramah HP**: bisa dipasang di HP, kamera langsung dari aplikasi                                                        | Petugas memotret formulir di lantai produksi                                                                 | Sedang                      |
| 2.13 | **Pencarian global** (No_Trs, mesin, operator) di header                                                                      | Navigasi cepat                                                                                               | Rendah                      |
| 2.14 | **Insight otomatis** (teks): "3 masalah teratas minggu ini…", "Mesin X naik 40%" (berbasis aturan, tanpa AI)                 | Ringkasan bagi yang tidak membaca chart                                                                      | Sedang                      |
| 2.15 | **Keamanan**: matikan/lindungi rute `/test/*` dan `/preview/*` di produksi, batasi upload (ukuran/tipe), rate limit login | Wajib sebelum dipakai                                                                                        | Tinggi                      |

---

## 3. Desain tampilan baru

### 3.1 Arah desain yang saya rekomendasikan (dipakai untuk saat ini, K6)

Gaya sekarang (monokrom + glass + glow) sudah rapi, tetapi untuk dashboard yang penuh chart, monokrom membuat data sulit dibedakan. Rekomendasi saya: **"Industrial Clean Dashboard"**:

- **Dasar netral** (abu sangat terang / gelap pekat di dark mode). Token di `_tokens.scss` tetap dipakai sebagai fondasi.
- **Satu warna aksen** untuk aksi/brand: **oranye `#ea580c`** (gelap: `#fb923c`), diputuskan 17 Oktober 2026 menggantikan biru. Netral memakai abu hangat (stone), heatmap dan komposisi shift memakai gradasi oranye; warna kategori D01–D07 tetap beragam supaya bisa dibedakan.
- **Palet chart terpisah** yang konsisten di semua halaman: 7 warna kategori masalah D01–D07 yang **selalu sama** di setiap chart (D03 di Pareto = D03 di Gantt = D03 di legenda), dan warna status (hijau/kuning/merah) hanya untuk status.
- **Kartu KPI besar** dengan angka tebal, sparkline, dan ▲/▼ berwarna.
- **Layout:** sidebar kiri yang bisa diciutkan (ikon saja), header berisi filter global + pilihan tema + profil, konten dalam grid 12 kolom (desktop 3–4 kartu per baris, tablet 2, HP 1).
- **Glass dipakai sedikit saja** (header/sidebar), bukan di kartu chart, karena latar transparan mengganggu keterbacaan chart.
- **Tipografi:** Inter (sudah terpasang) dengan angka *tabular* (`font-variant-numeric: tabular-nums`) supaya angka di tabel/KPI rata.
- **Ikon:** satu set konsisten (mis. Lucide/Tabler Icons) menggantikan SVG yang ditulis manual satu per satu.
- **Status kosong & loading:** skeleton saat chart dimuat, dan pesan jelas bila tidak ada data ("Tidak ada downtime pada periode ini 🎉").
- Tetap **Bootstrap 5 + SCSS** yang sudah ada. Tidak perlu pindah framework (Tailwind sudah ada di devDependencies tetapi tidak perlu dicampur).

### 3.2 Cara memberi referensi desain ke saya

Saya bisa **melihat gambar**, jadi cara paling efektif adalah memberi screenshot:

1. **Cari referensi** di: [Dribbble](https://dribbble.com/search/manufacturing-dashboard), [Behance](<https://www.behance.net/search/projects?search=industrial%20dashboard>), [Mobbin](https://mobbin.com) (UI aplikasi nyata), [Pinterest](https://pinterest.com) ("factory dashboard ui", "admin dashboard dark"), atau template seperti Tabler, CoreUI, AdminLTE, Vercel/Linear/Stripe dashboard.
2. **Simpan screenshot** (PNG/JPG) ke folder **`perencanaan/referensi-desain/`**, beri nama yang jelas, mis. `01-layout-sidebar.png`, `02-kartu-kpi.png`, `03-warna-dark.png`.
3. **Beri tahu saya** path-nya, atau tempel/seret gambar langsung ke chat Claude Code. Dengan cara apa pun, saya bisa membaca gambarnya.
4. **Sebutkan bagian yang Anda suka dari tiap gambar**, karena satu gambar jarang cocok seluruhnya. Contoh: *"layout & sidebar dari 01, gaya kartu KPI dari 02, warna dark mode dari 03, jangan pakai gradasinya"*. Ini sangat membantu.
5. **Link saja kurang andal**: halaman Dribbble/Behance/Pinterest sering tidak bisa saya buka (butuh login / dimuat lewat JavaScript). Screenshot selalu bisa.

**Proses yang saya usulkan:**

1. Anda kirim 3–6 referensi beserta catatan.
2. Saya buat **mockup HTML statis** (halaman Ringkasan dengan data contoh, light & dark) sebagai artifact/preview untuk Anda nilai, **sebelum** menyentuh Blade.
3. Revisi 1–2 kali sampai cocok.
4. Setelah disetujui, gaya dituangkan ke `_tokens.scss` + komponen, lalu diterapkan ke semua halaman.

Kalau belum menemukan referensi, saya bisa membuat **2–3 alternatif gaya** sebagai mockup (mis. "Clean Light Blue", "Dark Industrial Teal", "Monokrom + aksen oranye") untuk Anda pilih.

**Untuk saat ini (K6):** langsung dibuat 1 mockup HTML Ringkasan dengan gaya 3.1 (light & dark) untuk dinilai. Supaya mudah diganti bila nanti ada referensi baru, semua warna, radius, bayangan, dan palet chart hanya didefinisikan di `_tokens.scss` + satu file tema ECharts (`resources/js/charts/theme.js`). Mengganti gaya cukup mengubah dua file itu, tanpa menyentuh setiap halaman.

---

## 4. Implementasi Eksplorasi 6 ke alur produksi

**Tujuan:** halaman `/paper-scan` cukup **1 foto seluruh halaman**, tanpa 3 klik titik dan tanpa Ollama. Hasilnya: header (tanggal, mesin, shift, operator, Size, Speed) + Lost time semua baris shift yang bertinta, lalu petugas meninjau sebelum menyimpan.

### 4.1 Rancangan alur

```
Petugas: foto 1 lembar utuh
   │
   ▼
Laravel  POST /paper-scan/e6/analyze  (upload → simpan ke storage/app/scan-history/…)
   │  menyerahkan pekerjaan ke layanan Python E6 (E6-3), langsung membalas job_id
   │  UI polling GET /paper-scan/e6/status/{job_id} sampai selesai (E6-5)
   ▼
Python E6 (baca_fullpage_eksplorasi.py)
   │  SIFT → kanvas formulir → header + Lost time + keyakinan + 3 kandidat
   │  status: success / needs_retake / error
   ▼
Laravel: adapter E6 → format yang dipahami PaperExtractionProcessor
   │  (grid_waktu per shift → GridTimeMerger → baris downtime;
   │   mesin → MesinResolver + alias; operator → OperatorMatcher;
   │   kode → ProblemCodeConverter)
   ▼
Layar tinjauan (review)
   │  • Header: isian awal yang bisa dikoreksi; tanggal invalid / hari tidak cocok → peringatan
   │  • Operator SELALU dipilih/dikonfirmasi dari master (TMMESINOP)
   │  • Grid Lost time mirip formulir; kotak ragu (keyakinan < 0,85) ditandai kuning
   │    → klik → pilih dari 3 kandidat atau ketik
   │  • Pratinjau baris downtime hasil gabungan (jam mulai–selesai, kategori)
   ▼
Simpan  POST /paper-scan/store (endpoint yang sudah ada)
   + simpan final.json (hasil setelah koreksi + No_Trs) ke folder scan-history yang sama (tanpa DB)
```

### 4.2 Langkah kerja

| #     | Langkah                                    | Rincian                                                                                                                                                                                                                                                                                          |
| ----- | ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| E6-1  | **Validasi data baru dulu**          | Kumpulkan 5–10 foto utuh dari lembar & penulis berbeda + kunci jawaban, jalankan `bandingkan_e6.py` **tanpa mengubah** ambang/pola. Target: Lost time ≥ 88% tanpa konfirmasi, ≥ 97% dengan konfirmasi, header ≥ 90%. Bila jauh di bawah target, perbaiki dulu, lalu uji di set lain. |
| E6-2  | **Kunci kontrak JSON**               | Dokumentasikan output `baca_fullpage_eksplorasi.py` (status, header + keyakinan, lost_time per shift: kode, keyakinan, kandidat, bbox crop) sebagai kontrak tetap.                                                                                                                             |
| E6-3  | **Layanan Python tetap berjalan**    | Setiap pemanggilan skrip menghabiskan ±15 dtk hanya untuk start Python + load model. Buat server kecil (FastAPI/Flask, localhost) yang memuat TrOCR sekali saja. Laravel memanggil via HTTP. Sediakan fallback ke mode shell-out bila layanan mati.                                             |
| E6-4  | **Service Laravel**                  | `App\Services\PaperExtraction\FullPageReader` (memanggil layanan/skrip, timeout, error handling) + `E6ResultAdapter` (JSON E6 → struktur yang dipakai `PaperExtractionProcessor`). Pakai ulang `MesinResolver`, `OperatorMatcher`, `ProblemCodeConverter`, `GridTimeMerger`.      |
| E6-5  | **Proses di latar belakang (queue)** | 41–77 dtk terlalu lama untuk 1 request HTTP. Laravel Queue driver database **tidak dipakai** (butuh tabel `jobs`, K3). Penggantinya: layanan Python E6-3 menerima pekerjaan, memprosesnya di thread sendiri, dan menulis progres ke `status.json` di folder scan. Laravel cukup membaca file itu saat UI polling, lalu menampilkan progress bar ("Meluruskan foto… Membaca header… Membaca baris 2/3…"). Bila layanan tidak berjalan, cadangannya adalah menjalankan skrip sebagai proses latar belakang (`Symfony Process` tanpa menunggu) yang juga menulis `status.json`. |
| E6-6  | **Layar tinjauan**                   | Seperti 4.1, dengan grid yang menyerupai formulir dan crop kotak asli di sebelah hasil baca, supaya petugas bisa membandingkan langsung.                                                                                                                                                         |
| E6-7  | **Penanganan gagal**                 | `needs_retake` → tampilkan alasan + panduan foto (keempat sisi formulir terlihat, tidak miring berlebihan, terang, tidak buram). Tombol "Isi manual" selalu tersedia.                                                                                                                         |
| E6-8  | **Panduan foto di UI**               | Bingkai panduan di kamera (HP) + contoh foto yang baik/buruk.                                                                                                                                                                                                                                    |
| E6-9  | **Transisi**                         | Alur lama (Section 1 + 2, Ollama) tetap ada sebagai opsi "mode lama" selama masa uji, lalu dipensiunkan bila E6 stabil.                                                                                                                                                                          |
| E6-10 | **Deploy**                           | Python + venv (torch CPU, transformers, opencv) di server, model TrOCR (±250 MB) diunduh sekali ke folder lokal (tanpa internet saat produksi), konfigurasi di `.env` (`EKSPLORASI_PYTHON_BIN`, URL layanan). Ollama/GPU tidak lagi wajib.                                                  |
| E6-11 | **Log & dataset**                    | Simpan foto + hasil AI + koreksi petugas sebagai file (fitur 2.6, tanpa DB). Data ini menjadi bahan evaluasi berkelanjutan, kunci jawaban otomatis untuk `bandingkan_e6.py`, dan dataset fine-tuning nanti.                                                                                       |

### 4.3 Risiko E6

- Data uji baru 2 lembar / kemungkinan 1 penulis, sehingga akurasi bisa turun di lembar lain (karena itu ada E6-1).
- Nama operator tulisan tangan tidak andal, jadi harus selalu dikonfirmasi dari master.
- Waktu di CPU server bisa berbeda dengan laptop uji (i5-7200U), jadi perlu diukur ulang di server.
- Bila formulir cetak berubah tata letaknya, peta referensi E6 (`--buat-referensi`) harus dibuat ulang.

---

## 5. Urutan pengerjaan (usulan)

| Fase                                | Isi                                                                                                                                                          | Hasil                    |
| ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------ |
| **Fase 0 — Persiapan**       | ✅ K1–K6 dan 6.2 dijawab. Sisa: K7 (E6)                                                                                                                     | Arah jelas               |
| **Fase 1 — Desain**          | Mockup HTML Ringkasan gaya 3.1 (light & dark) → revisi → token & komponen baru (layout, sidebar, kartu KPI, kartu chart, tabel, filter)                   | Design system siap       |
| **Fase 2 — Analitik inti**   | `config/produksi.php`, service analitik + endpoint JSON, ECharts, halaman **Ringkasan** (KPI + R1–R7), filter global, hak akses baru (admin + petugas), Data Downtime yang ditingkatkan + export Excel | Sistem informasi versi 1 |
| **Fase 3 — Analitik lanjut** | Halaman Mesin (+ profil & Gantt), Masalah, Waktu & Shift, Produk/Item, Operator (khusus admin)                                                              | Analitik lengkap         |
| **Fase 4 — Eksplorasi 6**    | Menunggu K7. E6-1 (validasi) → layanan Python → service + adapter → proses latar belakang berbasis file → layar tinjauan → uji lapangan                | 1 foto, tanpa klik       |
| **Fase 5 — Pelengkap**       | Laporan PDF/Excel, target & ambang (file pengaturan), validasi data, audit log (file), statistik AI (file), keamanan rute test, mode TV/PWA                  | Siap produksi            |
| **Ditunda (butuh DB)**        | Peran Supervisor, kelola master, notifikasi ber-database/email                                                                                               | —                        |

Catatan: E6-1 (mengumpulkan & menguji foto baru) bisa dikerjakan **paralel** kapan saja karena tidak bergantung pada fase lain.

---

## 5a. Halaman Draft (evaluasi tanpa database)

Karena saat ini aplikasi tidak bisa terhubung ke database (sehingga tidak bisa login), semua desain dan fitur baru dibuat dulu sebagai **halaman draft** dengan **data dummy** (disepakati 16 Oktober 2026).

| Hal | Isi |
|---|---|
| Alamat | `/draft` (daftar draft), `/draft/ringkasan`, dst. Tambahkan `?peran=admin` untuk melihat sebagai admin (menu Operator & Admin muncul, Tambah Data hilang) |
| Syarat aktif | `APP_DEBUG=true` atau `DRAFT_ENABLED=true` (`config/produksi.php`). **Matikan di produksi** |
| Login/DB | Tidak perlu: tanpa middleware auth, tanpa query database |
| View | `resources/views/draft/` (layout sendiri: `draft/layouts/app.blade.php`) |
| Gaya | `resources/css/draft.scss` (token "Industrial Clean"), terpisah dari `app.scss`, jadi halaman lama tidak berubah selama evaluasi |
| Chart | ECharts (npm), `resources/js/draft/chart-theme.js` (tema dari CSS variables, ikut light/dark) + satu file JS per halaman |
| Data dummy | `App\Services\Analytics\Sources\DummyDowntimeSource`: deterministik (seed = tanggal), 14 mesin & 7 kategori fiktif, pola dibuat realistis |
| Perhitungan | `App\Services\Analytics\DowntimeAnalytics`, **dipakai juga nanti di halaman asli**. Hanya sumber datanya yang diganti (`Contracts\DowntimeSource` → `SqlDowntimeSource` berisi query `MFDOWNTIME` + join master) |

**Variasi desain (17 Oktober 2026).** Halaman Ringkasan dibuat dalam 3 gaya dengan data, filter, dan fitur yang sama, supaya bisa dibandingkan:

| Variasi | Alamat | Referensi | Ciri |
|---|---|---|---|
| Draft 1 ✅ **dipilih** (17-10-2026) | `/draft/ringkasan` | rekomendasi 3.1 | Industrial Clean: netral hangat + **aksen oranye** (revisi dari biru), terang/gelap |
| Draft 2 | `/draft2/ringkasan` | `images/reference/NexHome – Smart Living Dashboard UI.jpeg` | navy gelap, sidebar ikon + label, kartu gradasi hangat, gauge availability, kartu "shift sekarang", tile 4 mesin terburuk, bar 7 hari dengan puncak disorot |
| Draft 3 | `/draft3/ringkasan` | `images/reference/Smart Savings + AI = Financial Freedom.jpeg` | glassmorphism di atas gradasi cokelat-oranye/ungu, sidebar hitam, KPI bergradasi, bar memudar, line kuning |
| Draft 4 | `/draft4/ringkasan` | permintaan "sederhana & ramah segala umur" | huruf besar + tombol ukuran huruf (A/A+/A++), kontras tinggi, bahasa sehari-hari ("waktu mesin berhenti"), kotak **Intinya** berisi kalimat ringkasan, status selalu dengan teks/ikon (bukan warna saja), hanya 2 chart batang sederhana (per hari & per jam), sisanya daftar bergaris, navigasi atas + navigasi bawah di HP |

File per variasi: `resources/views/draft{2,3}/`, `resources/css/draft{2,3}.scss`, `resources/js/draft{2,3}/ringkasan.js`. Draft 2 dan 3 default gelap; `?tema=light` / `?tema=dark` di URL untuk memilih tema. Setelah satu gaya dipilih, gaya itu dipakai untuk semua halaman berikutnya, lalu variasi lain dihapus.

**Status draft:**

| Draft | Status |
|---|---|
| Ringkasan (KPI + R1–R7 + filter global + simulasi peran) | ✅ siap dievaluasi |
| Analisis Mesin (`/draft/mesin`, `/draft/mesin/{kode}`): M1 ranking (urut di browser, sparkline SVG, vs periode lalu), M2 treemap per awalan kode, M3 sebaran frekuensi × durasi dengan kuadran kritis (median), M4 bandingkan ≤ 4 mesin; profil: KPI, timeline 07:00→07:00 untuk 7 tanggal terakhir, tren, porsi kategori, 10 detail masalah, item + speed, operator (admin), riwayat | ✅ siap dievaluasi (17-10-2026) |
| Analisis Masalah (`/draft/masalah`): P1 Pareto kategori dengan drill-down ke detail (klik batang), P2 sunburst kategori → detail, ringkasan per kategori (porsi, kumulatif, mesin terbanyak, 3 detail teratas, vs lalu), P3 tren bertumpuk (mingguan bila > 45 hari), P4 matriks mesin × kategori, P5 boxplot durasi, P6 masalah berulang (≥ N× dalam 7 hari, N bisa dipilih) | ✅ siap dievaluasi (17-10-2026) |
| Waktu & Shift (`/draft/waktu`): W1 kalender (klik tanggal → timeline), W2 jam rawan + rata-rata per hari dalam minggu, W3 kartu & penyebab per shift (vs lalu), W4 tren mingguan vs periode lalu, W5 kapan masalah muncul dalam shift (per 30 menit), W6 timeline semua mesin 1 tanggal (07:00→07:00, navigasi tanggal, klik → profil mesin) | ✅ siap dievaluasi (17-10-2026) |
| Produk / Item (`/draft/produk`): I1 tabel per item (porsi, rata-rata, masalah dominan, mesin, speed, vs lalu), I2 matriks item × kategori, I3 speed vs downtime per rentang 100 m/mnt + boxplot speed per mesin | ✅ siap dievaluasi (17-10-2026) |
| Operator (`/draft/operator`, **khusus admin**, petugas → 403): label "bukan penilaian kinerja", O1 menit per shift tercatat + ringkasan, O2 komposisi kategori (100%), O3 aktivitas input petugas (jumlah, % AI, median jeda input, jeda > 8 jam, pernah diubah) | ✅ siap dievaluasi (17-10-2026) |
| Data Downtime (`/draft/data`): filter global + pencarian + sumber + operator (admin), urut per kolom, 10/25/50/100 baris, panel detail, export CSV (`;` + BOM, bisa dibuka Excel), admin baca-saja | ✅ siap dievaluasi (17-10-2026) |
| Tambah Data E6 + layar tinjauan | belum (menunggu K7) |

**Saat database sudah tersambung (alur promosi draft → asli):**
1. Buat `SqlDowntimeSource` (implementasi interface yang sama).
2. Pindahkan view draft ke folder asli, ganti layout ke layout utama, lalu pasang middleware sesuai tabel hak akses 1.1.
3. Gabungkan token `draft.scss` ke `_tokens.scss` (atau jadikan `draft.scss` sebagai `app.scss` baru).
4. Hapus rute `/draft` atau biarkan mati lewat `DRAFT_ENABLED=false`.

---

## 6. Keputusan

### 6.1 Jawaban (16 Oktober 2026)

| #  | Jawaban | Dampak pada rencana |
|----|---------|---------------------|
| K1 | Aturan "hanya file eksplorasi" **dilonggarkan** untuk pengembangan ini | File lama (routes, controller, layout, SCSS, paper-scan) boleh diubah |
| K2 | Analitik hanya untuk **admin dan petugas produksi** | Tabel hak akses di 1.1; peran Supervisor ditunda |
| K3 | **Database tidak diubah** untuk saat ini | Pengaturan, audit log, riwayat scan, dan status proses E6 disimpan di file; fitur yang butuh tabel ditunda |
| K4 | **480 menit**; shift 1 07–15, shift 2 15–23, shift 3 23–07 | Rumus Downtime %, jadwal di 1.2, `config/produksi.php` |
| K5 | Analitik operator **hanya admin** | Halaman & filter Operator khusus admin |
| K6 | Ikuti rekomendasi 3.1 dulu (bisa berubah nanti) | Mockup 1 gaya; gaya terpusat di token + tema chart |
| K7 | **Dibahas nanti** | Fase 4 menunggu |

### 6.2 Pertanyaan lanjutan (dijawab 16 Oktober 2026)

| Pertanyaan | Jawaban | Dampak |
|---|---|---|
| Ada target downtime resmi? | **Tidak ada** | Garis target disembunyikan; target opsional diisi admin nanti (2.2); acuan memakai periode sebelumnya + rata-rata 30 hari |
| Mesin yang dihitung? | **Semua mesin** di `MFRESMAS` | Waktu tersedia & kelengkapan input memakai semua mesin × 3 shift (1.2) |
| `Tgl_Trs` shift 3? | **Tergantung tanggal input**, bukan tanggal mulai shift (shift 3 = 1 jam di hari pertama + 7 jam di hari berikutnya) | `Tgl_Trs` dipakai apa adanya; analisis jam memakai jam `Time_Start`; Gantt bersumbu 07:00→07:00 (1.2) |

### 6.3 Pertanyaan awal (arsip)

| #            | Pertanyaan                                                                                                                                                                                                                                                                                                                                                                                                            | Kenapa penting                        |
| ------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------- |
| **K1** | **Aturan "hanya ubah file bernama eksplorasi" untuk fase ini.** Fitur analitik, desain baru, dan integrasi E6 pasti mengubah file lama (`routes/web.php`, `DashboardController`, `layouts/app.blade.php`, `_tokens.scss`, `paper-scan/index.blade.php`, dll.). Apakah aturan ini dilonggarkan untuk pengembangan ini, atau semua dibuat sebagai file/halaman baru yang berdampingan dengan yang lama? | Menentukan cara kerja seluruh rencana |
| **K2** | Siapa pengguna analitik: hanya petugas produksi, atau ada supervisor/manajer (peran baru)? Admin AFSYAH boleh melihat analitik?                                                                                                                                                                                                                                                                                       | Menentukan menu & hak akses           |
| **K3** | Bolehkah aplikasi membuat **tabel baru** sendiri (log scan, audit log, pengaturan target, antrian queue) di database? Di database mana?                                                                                                                                                                                                                                                                         | Fitur 2.5, 2.6, 2.9, target, queue    |
| **K4** | Durasi efektif per shift (480 menit? dikurangi istirahat?) dan jadwal shift 1/2/3                                                                                                                                                                                                                                                                                                                                     | Rumus Downtime % / availability       |
| **K5** | Apakah analitik per operator boleh ditampilkan? Untuk siapa?                                                                                                                                                                                                                                                                                                                                                          | Sensitivitas data karyawan            |
| **K6** | Arah desain: ikut rekomendasi 3.1, atau menunggu referensi dari Anda?                                                                                                                                                                                                                                                                                                                                                 | Fase 1                                |
| **K7** | Untuk E6: kapan bisa mendapat 5–10 foto lembar baru, dan apakah server produksi sanggup menjalankan Python + torch (CPU)?                                                                                                                                                                                                                                                                                            | Fase 4                                |
