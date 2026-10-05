# Hasil Perbandingan Eksplorasi 1 vs Eksplorasi 2

Dokumen ini merangkum pengembangan dan pengujian modul ekstraksi tabel *Lost time*
dari foto formulir, dengan membandingkan **Eksplorasi 1 (baca per kotak)** dan
**Eksplorasi 2 (baca per blok)** pada 12 foto di folder `images`.

- Tanggal uji: 4 Oktober 2026
- Model: `qwen2.5vl:3b` (Ollama lokal)
- Shift yang diuji: 1 dan 2 (baris shift 3 kosong di kedua lembar)
- Skrip pembanding: `scripts/bandingkan_eksplorasi.py`
- Data mentah: `scripts/output_bandingkan_eksplorasi/`

## 1. Ringkasan

| | E1 per-kotak | E2 per-blok |
|---|--:|--:|
| Total waktu (24 run) | 1094.4 dtk | 860.3 dtk |
| Rata-rata per foto per shift | 45.6 dtk | 35.8 dtk |
| Akurasi semua kotak | 1009/1147 (88.0%) | 1097/1147 (95.6%) |
| Akurasi kotak berisi tulisan | 163/301 (54.2%) | 251/301 (83.4%) |
| Akurasi kotak kosong | 100% | 100% |

**Kesimpulan:** E2 (per blok) lebih akurat. E1 (per kotak) hanya lebih cepat pada
lembar yang jarang terisi, dan jauh lebih lambat serta kurang akurat pada lembar padat.

| Kondisi | E1 per-kotak | E2 per-blok |
|---|---|---|
| Lembar padat, shift 1 (5 foto) | ~155 dtk, 48 panggilan, 23–29/47 benar | ~78–89 dtk, 9–12 panggilan, 28–45/47 benar |
| Lembar jarang, shift 1 (7 foto) | ~33 dtk, 8 panggilan, 40–46/48 benar | ~48–56 dtk, 6–10 panggilan, 41–48/48 benar |
| Shift 2, lembar padat (2 kotak terisi) | ~15 dtk, 47/48 benar | ~17 dtk, 48/48 benar |
| Shift 2, lembar jarang (kosong semua) | ~2 dtk, 48/48 benar | ~2 dtk, 48/48 benar |

## 2. Perbaikan yang dilakukan

### 2.1 Masalah awal: posisi grid tidak pas
Pada versi awal, posisi grid hanya didapat dari homography ORB terhadap satu foto
referensi, ditambah koreksi offset dari OCR label "Jam". Akibatnya:

- garis atas/bawah baris bisa bergeser sampai setengah baris dan miring;
- 48 kotak dibagi rata dari referensi, sehingga tidak menempel ke garis cetak;
- garis kolom selalu tegak lurus terhadap gambar, padahal di foto garisnya miring
  dan kemiringannya berubah sepanjang baris (efek perspektif).

Dampaknya nyata: pada foto `IMG_20260921_004756`, crop lama menaruh "7a" dan "2a"
di kolom 1 dan 3, padahal sebenarnya ada di kolom 2 dan 4.

### 2.2 Refine ke garis cetak (`refine_grid_eksplorasi.py`)
Hasil homography tetap dipakai sebagai posisi awal (supaya tetap diketahui blok mana
untuk jam berapa), lalu setiap garis ditempelkan ke garis cetak asli terdekat dalam
jendela pencarian kecil:

1. Baris diluruskan menjadi *strip*, ditambah koreksi kemiringan garis vertikal yang
   bervariasi sepanjang baris.
2. Garis atas/bawah baris *Lost time* dicari per blok, dibedakan dari baris "Jam"
   dari ada/tidaknya garis kotak kecil.
3. Batas blok dicari lebih dulu: hanya garis batas blok yang menembus baris "Jam",
   sehingga tidak mungkin tertukar dengan garis kotak kecil.
4. Lima garis kotak kecil per blok ditempelkan ke garis terdekat, lalu dicek
   konsistensi jaraknya.

Cara ini sengaja tidak mencari garis di seluruh gambar, karena pendekatan Hough global
sebelumnya gagal (jumlah blok berlebih atau tertukar).

### 2.3 Deteksi tinta per kotak
Setiap kotak diluruskan dan diukur proporsi pikselnya yang gelap. Pada semua foto uji,
kotak kosong terukur **0%** dan kotak berisi **di atas 7,8%** (ambang yang dipakai: 3%).
Kotak kosong diputuskan tanpa model, dan blok yang kosong semua tidak dikirim ke Ollama.

### 2.4 Geometri jalur cepat (tanpa OSD dan OCR)
OSD (~3 dtk) dan OCR (~2–8 dtk) memakan sekitar 90% waktu geometri. Keduanya dilewati:
rotasi foto ditebak dari arah grid hasil homography, dan cek silang OCR digantikan refine.

| | Sebelum | Sesudah |
|---|---|---|
| Waktu geometri per foto | 5–12 dtk | 1–2 dtk |
| Foto yang berhasil | 10/12 | 12/12 |
| Selisih posisi grid | – | ≤ 0,6 piksel |

`IMG_20260902_141316` (OSD gagal) dan `IMG_20260902_141238` (OSD salah putar) kini
bisa diproses. Foto 141238 berstatus `needs_manual_review` karena buram akibat gerakan.
Jalur lama tetap tersedia lewat opsi `--jalur-lama`.

### 2.5 Prompt baru untuk Eksplorasi 2
Model 3B terbukti menyalin contoh nilai di prompt lama (`"6a"`/`"5b"`) ke kotak yang
isinya "x". Prompt baru (`prompt_refine_eksplorasi.py`) tidak memakai contoh nilai,
memakai template JSON per blok dari hasil deteksi tinta, dan menanyakan ulang kotak
yang tidak terbaca (hasilnya ditandai `forced_guess`).

## 3. Alur setiap eksplorasi

### Eksplorasi 1 (versi asli): geometri saja
1. Baca foto, putar sesuai OSD Tesseract.
2. Cocokkan titik fitur ORB dengan foto referensi, hitung homography.
3. Petakan posisi baris dan 49 garis kolom dari referensi ke foto.
4. Cek silang dengan OCR label "Jam".
5. Hasil: overlay untuk dicek manual, tanpa nilai.

### Eksplorasi 1 (sekarang): baca per kotak, gaya Mode E
Skrip: `scripts/eksplorasi1/baca_kotak_eksplorasi.py`
1. Homography dari foto mentah, rotasi ditebak dari arah grid.
2. Refine: tempelkan garis ke garis cetak asli.
3. Ukur tinta di 48 kotak; kotak kosong langsung `null`.
4. Setiap kotak berisi di-crop dan diluruskan sendiri, lalu dibaca Ollama satu kotak per panggilan.
5. Hasil disusun menjadi 8 blok × 6 kotak.

### Eksplorasi 2 (versi asli): baca per blok
1. Geometri sama dengan Eksplorasi 1 asli.
2. Crop kasar per blok jam, diberi ruang ekstra di atas supaya label jam ikut terlihat.
3. Gambar garis bantu merah/cyan dengan posisi dibagi rata.
4. Ollama membaca 6 kotak per panggilan (8 panggilan per foto).

### Eksplorasi 2 (sekarang)
Skrip: `scripts/eksplorasi2/ollama_block_pipeline_refine_eksplorasi.py`
1. Geometri jalur cepat dan refine, sama dengan Eksplorasi 1 sekarang.
2. Setiap blok diluruskan (perspective warp), garis bantu tepat di garis cetak.
3. Deteksi tinta: blok kosong tidak dikirim ke Ollama, jawaban model untuk kotak kosong dibuang.
4. Prompt template JSON per blok; kotak yang tidak terbaca ditanyakan ulang sekali.

### Eksplorasi 3: tanpa crop
1. Foto diputar (OSD), diperbesar, dan kontrasnya ditingkatkan (CLAHE).
2. Foto utuh yang sama dikirim 8 kali, sekali per blok jam.
3. Model sendiri yang harus menemukan baris shift dan blok yang dimaksud, lalu membacanya.

## 4. Analisis kesalahan

Semua crop sudah dicek visual dan bersih, sehingga kesalahan yang tersisa berasal
dari cara model membaca tulisan tangan, bukan dari posisi.

**E1 per-kotak** — model kesulitan membaca satu kotak tanpa konteks:
- "0" dijawab "?" (34 kali) atau "0x" (22 kali)
- "2b" jadi "x" (5), "2a" jadi "2q" (5) atau "20" (4)
- "3i" jadi "31" (3), "3c" jadi "3L" (3), "7b" jadi "0t" (3)

**E2 per-blok** — kesalahannya lebih sempit:
- blok penuh "0" dibaca "x" (18 kali), "0" jadi "00" (5)
- "4i" jadi "41" (5)
- huruf besar/kecil tertukar ("2F" jadi "2f", "2a" jadi "2A", dan seterusnya)

## 5. Catatan

- **Kunci jawaban** (`scripts/kunci_jawaban_eksplorasi.json`) disusun dari pengamatan
  visual foto, bukan data resmi. Kotak coretan tebal di blok 08.00–09.00 lembar padat
  tidak dinilai (`SKIP`).
- **12 foto = 2 lembar kertas** yang difoto berulang: seri `1345xx` adalah lembar padat,
  seri `1412xx` dan `004756` lembar jarang. Jumlah foto lembar padat masih sedikit,
  jadi angkanya sebaiknya dibaca sebagai gambaran.
- **Hasil model berubah antar-run.** Pada uji sebelumnya foto 134505 hanya 20/47 dengan
  E2; pada uji ini 28/47.
- **Waktu sudah termasuk start Python**, karena setiap eksplorasi dijalankan sebagai
  proses terpisah seperti saat dipanggil Laravel. Model dipanaskan sekali sebelum uji.
- **Laravel belum memakai versi baru.** Controller masih memanggil skrip lama; untuk
  memakai versi baru cukup ganti `$scriptPath`.

## Lampiran A. Waktu dan skor per foto

Waktu dalam detik. "Benar" = jumlah kotak yang cocok dengan kunci / jumlah kotak yang dinilai.

| Foto | Shift | E1 total | E1 geometri | E1 Ollama | E1 panggilan | E1 benar | E2 total | E2 geometri | E2 Ollama | E2 panggilan | E2 benar |
|---|:-:|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|
| IMG_20260901_134505 | 1 | 150.4 | 1.4 | 148.3 | 48 | 29/47 | 82.8 | 1.64 | 80.4 | 10 | 28/47 |
| IMG_20260901_134505 | 2 | 14.6 | 1.48 | 12.4 | 2 | 47/48 | 16.7 | 1.72 | 14.3 | 1 | 48/48 |
| IMG_20260901_134510 | 1 | 155.1 | 1.45 | 152.8 | 48 | 27/47 | 77.3 | 1.59 | 74.9 | 9 | 45/47 |
| IMG_20260901_134510 | 2 | 15.0 | 1.47 | 12.8 | 2 | 47/48 | 16.8 | 1.46 | 14.6 | 1 | 48/48 |
| IMG_20260901_134514 | 1 | 155.8 | 1.49 | 153.5 | 48 | 23/47 | 80.9 | 1.62 | 78.5 | 10 | 45/47 |
| IMG_20260901_134514 | 2 | 15.8 | 1.61 | 13.5 | 2 | 47/48 | 16.9 | 1.57 | 14.6 | 1 | 48/48 |
| IMG_20260901_134520 | 1 | 155.6 | 1.72 | 153.0 | 48 | 27/47 | 88.6 | 1.87 | 86.0 | 12 | 32/47 |
| IMG_20260901_134520 | 2 | 15.5 | 1.83 | 13.0 | 2 | 47/48 | 17.0 | 1.75 | 14.6 | 1 | 48/48 |
| IMG_20260901_134523 | 1 | 155.8 | 1.7 | 153.3 | 48 | 25/47 | 82.7 | 2.05 | 79.9 | 10 | 45/47 |
| IMG_20260901_134523 | 2 | 16.1 | 1.87 | 13.5 | 2 | 47/48 | 17.3 | 1.81 | 14.8 | 1 | 48/48 |
| IMG_20260902_141208 | 1 | 33.6 | 1.4 | 31.5 | 8 | 44/48 | 49.7 | 1.54 | 47.5 | 7 | 47/48 |
| IMG_20260902_141208 | 2 | 2.1 | 1.44 | 0.0 | 0 | 48/48 | 1.9 | 1.3 | 0 | 0 | 48/48 |
| IMG_20260902_141212 | 1 | 32.4 | 1.1 | 30.7 | 8 | 46/48 | 48.8 | 1.56 | 46.6 | 6 | 48/48 |
| IMG_20260902_141212 | 2 | 2.1 | 1.48 | 0.0 | 0 | 48/48 | 1.9 | 1.29 | 0 | 0 | 48/48 |
| IMG_20260902_141217 | 1 | 33.4 | 1.32 | 31.5 | 8 | 45/48 | 49.0 | 1.52 | 46.8 | 6 | 48/48 |
| IMG_20260902_141217 | 2 | 2.1 | 1.47 | 0.0 | 0 | 48/48 | 1.8 | 1.22 | 0 | 0 | 48/48 |
| IMG_20260902_141223 | 1 | 32.3 | 1.11 | 30.6 | 8 | 43/48 | 50.3 | 1.72 | 47.8 | 7 | 48/48 |
| IMG_20260902_141223 | 2 | 2.3 | 1.6 | 0.0 | 0 | 48/48 | 1.9 | 1.33 | 0 | 0 | 48/48 |
| IMG_20260902_141238 | 1 | 32.8 | 1.18 | 31.1 | 8 | 40/48 | 55.7 | 1.6 | 53.4 | 10 | 41/48 |
| IMG_20260902_141238 | 2 | 2.3 | 1.63 | 0.0 | 0 | 48/48 | 2.0 | 1.45 | 0 | 0 | 48/48 |
| IMG_20260902_141316 | 1 | 32.4 | 1.02 | 30.8 | 8 | 45/48 | 48.3 | 1.38 | 46.2 | 6 | 48/48 |
| IMG_20260902_141316 | 2 | 2.0 | 1.37 | 0.0 | 0 | 48/48 | 1.8 | 1.17 | 0 | 0 | 48/48 |
| IMG_20260921_004756 | 1 | 32.6 | 1.26 | 30.7 | 8 | 44/48 | 48.2 | 1.58 | 46.0 | 6 | 46/48 |
| IMG_20260921_004756 | 2 | 2.3 | 1.66 | 0.0 | 0 | 48/48 | 2.0 | 1.41 | 0 | 0 | 48/48 |

## Lampiran B. Nilai tiap blok (shift 1)

`·` = kotak kosong, **tebal** = berbeda dengan kunci.

#### IMG_20260901_134505

| Blok | Kunci | E1 per-kotak | E2 per-blok |
|:-:|---|---|---|
| 0 | 4i 7b 1a 1b 1c 1d | 4i **0x** 1a 1b 1c **1a** | **41** 7b 1a 1b **1L** **12** |
| 1 | 0 0 0 (skip) 0 0 | 0 **?** 0 0x 0 0 | 0 0 0 x 0 0 |
| 2 | 2a 2b 2c 2d 2e 2F | **20** **x** 2c **1l** 2e 2F | **2A** **2B** **2C** **2D** **2E** 2F |
| 3 | 3a 3b 3c 3d 3e 0 | 3a 3b **3L** 3d 3e 0 | 3a 3b 3c 3d 3e 0 |
| 4 | 0 0 0 0 0 0 | 0 0 0 **?** 0 **?** | 0 0 0 0 0 0 |
| 5 | 0 0 0 0 0 0 | **0x** 0 0 **?** **0x** 0 | **x** **x** **x** **x** **x** **x** |
| 6 | 0 0 0 0 0 0 | **0x** 0 0 0 **?** **0x** | 0 0 0 0 0 0 |
| 7 | 0 0 0 0 0 1a | **0a** 0 0 0 **?** **1q** | **00** **00** **00** **00** **00** 1a |

#### IMG_20260901_134510

| Blok | Kunci | E1 per-kotak | E2 per-blok |
|:-:|---|---|---|
| 0 | 4i 7b 1a 1b 1c 1d | 4i **0x** 1a 1b **x** **?** | **41** 7b 1a 1b 1c 1d |
| 1 | 0 0 0 (skip) 0 0 | 0 0 0 ? 0 0 | 0 0 0 x 0 0 |
| 2 | 2a 2b 2c 2d 2e 2F | **10** **x** 2c **1a** 2e 2F | **2U** 2b 2c 2d 2e 2F |
| 3 | 3a 3b 3c 3d 3e 0 | 3a 3b **?l** 3d 3e 0 | 3a 3b 3c 3d 3e 0 |
| 4 | 0 0 0 0 0 0 | 0 0 **0x** **?** 0 **0x** | 0 0 0 0 0 0 |
| 5 | 0 0 0 0 0 0 | **?** **0x** 0 **?** **?** 0 | 0 0 0 0 0 0 |
| 6 | 0 0 0 0 0 0 | 0 0 0 **0x** 0 **?** | 0 0 0 0 0 0 |
| 7 | 0 0 0 0 0 1a | **?** 0 **0x** 0 **?** **1q** | 0 0 0 0 0 1a |

#### IMG_20260901_134514

| Blok | Kunci | E1 per-kotak | E2 per-blok |
|:-:|---|---|---|
| 0 | 4i 7b 1a 1b 1c 1d | **x** **0t** 1a 1b **0x** **1x** | **41** 7b 1a 1b 1c 1d |
| 1 | 0 0 0 (skip) 0 0 | 0 0 **0x** 0x 0 **01** | 0 0 0 x 0 0 |
| 2 | 2a 2b 2c 2d 2e 2F | **20** **x** **0x** **2a** 2e 2F | **2U** 2b 2c 2d 2e 2F |
| 3 | 3a 3b 3c 3d 3e 0 | 3a **3b1** **3L** **?l** 3e 0 | 3a 3b 3c 3d 3e 0 |
| 4 | 0 0 0 0 0 0 | 0 0 **?** **?** 0 **?** | 0 0 0 0 0 0 |
| 5 | 0 0 0 0 0 0 | **0x** **0x** 0 0 **0Q** **0x** | 0 0 0 0 0 0 |
| 6 | 0 0 0 0 0 0 | **0x** 0 0 **?** 0 **0x** | 0 0 0 0 0 0 |
| 7 | 0 0 0 0 0 1a | **?** 0 0 0 0 1a | 0 0 0 0 0 1a |

#### IMG_20260901_134520

| Blok | Kunci | E1 per-kotak | E2 per-blok |
|:-:|---|---|---|
| 0 | 4i 7b 1a 1b 1c 1d | **x** **0t** 1a 1b 1c **x** | **41** 7b 1a 1b 1c 1d |
| 1 | 0 0 0 (skip) 0 0 | 0 **?** 0 0x 0 0 | 0 0 0 x 0 0 |
| 2 | 2a 2b 2c 2d 2e 2F | **20** **x** **?** **1n** 2e 2F | **20** 2b 2c 2d 2e **2f** |
| 3 | 3a 3b 3c 3d 3e 0 | 3a 3b **3L** 3d 3e 0 | 3a 3b 3c 3d 3e 0 |
| 4 | 0 0 0 0 0 0 | **?** 0 0 **0x** 0 **?** | **x** **x** **x** **x** **x** **x** |
| 5 | 0 0 0 0 0 0 | **0x** **?** 0 0 **0x** **?** | **x** **x** **x** **x** **x** **x** |
| 6 | 0 0 0 0 0 0 | 0 0 **0x** 0 **?** **x** | 0 0 0 0 0 0 |
| 7 | 0 0 0 0 0 1a | **?** 0 0 0 0 1a | 0 0 0 0 0 1a |

#### IMG_20260901_134523

| Blok | Kunci | E1 per-kotak | E2 per-blok |
|:-:|---|---|---|
| 0 | 4i 7b 1a 1b 1c 1d | **41** **0t** 1a 1b **?** **x** | **41** 7b 1a 1b 1c 1d |
| 1 | 0 0 0 (skip) 0 0 | 0 0 **?** ? 0 0 | 0 0 0 0 0 0 |
| 2 | 2a 2b 2c 2d 2e 2F | **20** **x** **x** **1n** 2e 2F | 2a 2b 2c 2d 2e **2f** |
| 3 | 3a 3b 3c 3d 3e 0 | 3a 3b **?l** **?** 3e 0 | 3a 3b 3c 3d 3e 0 |
| 4 | 0 0 0 0 0 0 | **0x** 0 **?** **?** 0 **?** | 0 0 0 0 0 0 |
| 5 | 0 0 0 0 0 0 | **?** 0 0 0 0 0 | 0 0 0 0 0 0 |
| 6 | 0 0 0 0 0 0 | 0 0 **?** 0 **?** **?** | 0 0 0 0 0 0 |
| 7 | 0 0 0 0 0 1a | **?** **0x** 0 0 **0x** 1a | 0 0 0 0 0 1a |

#### IMG_20260902_141208

| Blok | Kunci | E1 per-kotak | E2 per-blok |
|:-:|---|---|---|
| 0 | · · · · 6a 5b | · · · · 6a **1b** | · · · · 6a 5b |
| 1 | · · · · · · | · · · · · · | · · · · · · |
| 2 | · 7a · 2a · · | · **1a** · **2q** · · | · 7a · 2a · · |
| 3 | · 2F · · 3i · | · 2F · · 3i · | · 2F · · 3i · |
| 4 | · 3c · · · · | · 3c · · · · | · **x** · · · · |
| 5 | · · · · · · | · · · · · · | · · · · · · |
| 6 | · · · · · · | · · · · · · | · · · · · · |
| 7 | · · · · · x | · · · · · **?** | · · · · · x |

#### IMG_20260902_141212

| Blok | Kunci | E1 per-kotak | E2 per-blok |
|:-:|---|---|---|
| 0 | · · · · 6a 5b | · · · · 6a **x** | · · · · 6a 5b |
| 1 | · · · · · · | · · · · · · | · · · · · · |
| 2 | · 7a · 2a · · | · 7a · **2q** · · | · 7a · 2a · · |
| 3 | · 2F · · 3i · | · 2F · · 3i · | · 2F · · 3i · |
| 4 | · 3c · · · · | · 3c · · · · | · 3c · · · · |
| 5 | · · · · · · | · · · · · · | · · · · · · |
| 6 | · · · · · · | · · · · · · | · · · · · · |
| 7 | · · · · · x | · · · · · x | · · · · · x |

#### IMG_20260902_141217

| Blok | Kunci | E1 per-kotak | E2 per-blok |
|:-:|---|---|---|
| 0 | · · · · 6a 5b | · · · · **ba** 5b | · · · · 6a 5b |
| 1 | · · · · · · | · · · · · · | · · · · · · |
| 2 | · 7a · 2a · · | · 7a · **2q** · · | · 7a · 2a · · |
| 3 | · 2F · · 3i · | · 2F · · **31** · | · 2F · · 3i · |
| 4 | · 3c · · · · | · 3c · · · · | · 3c · · · · |
| 5 | · · · · · · | · · · · · · | · · · · · · |
| 6 | · · · · · · | · · · · · · | · · · · · · |
| 7 | · · · · · x | · · · · · x | · · · · · x |

#### IMG_20260902_141223

| Blok | Kunci | E1 per-kotak | E2 per-blok |
|:-:|---|---|---|
| 0 | · · · · 6a 5b | · · · · 6a **sb** | · · · · 6a 5b |
| 1 | · · · · · · | · · · · · · | · · · · · · |
| 2 | · 7a · 2a · · | · 7a · **2q** · · | · 7a · 2a · · |
| 3 | · 2F · · 3i · | · **1F** · · **31** · | · 2F · · 3i · |
| 4 | · 3c · · · · | · 3c · · · · | · 3c · · · · |
| 5 | · · · · · · | · · · · · · | · · · · · · |
| 6 | · · · · · · | · · · · · · | · · · · · · |
| 7 | · · · · · x | · · · · · **?** | · · · · · x |

#### IMG_20260902_141238

| Blok | Kunci | E1 per-kotak | E2 per-blok |
|:-:|---|---|---|
| 0 | · · · · 6a 5b | · · · · **0x** **?** | · · · · **x** **x** |
| 1 | · · · · · · | · · · · · · | · · · · · · |
| 2 | · 7a · 2a · · | · **1x** · **09x** · · | · **x** · **x** · · |
| 3 | · 2F · · 3i · | · **8x** · · **0x** · | · **x** · · **x** · |
| 4 | · 3c · · · · | · **36** · · · · | · **x** · · · · |
| 5 | · · · · · · | · · · · · · | · · · · · · |
| 6 | · · · · · · | · · · · · · | · · · · · · |
| 7 | · · · · · x | · · · · · **?** | · · · · · x |

#### IMG_20260902_141316

| Blok | Kunci | E1 per-kotak | E2 per-blok |
|:-:|---|---|---|
| 0 | · · · · 6a 5b | · · · · 6a 5b | · · · · 6a 5b |
| 1 | · · · · · · | · · · · · · | · · · · · · |
| 2 | · 7a · 2a · · | · **?** · **29** · · | · 7a · 2a · · |
| 3 | · 2F · · 3i · | · 2F · · 3i · | · 2F · · 3i · |
| 4 | · 3c · · · · | · 3c · · · · | · 3c · · · · |
| 5 | · · · · · · | · · · · · · | · · · · · · |
| 6 | · · · · · · | · · · · · · | · · · · · · |
| 7 | · · · · · x | · · · · · **?** | · · · · · x |

#### IMG_20260921_004756

| Blok | Kunci | E1 per-kotak | E2 per-blok |
|:-:|---|---|---|
| 0 | · · · · 6a 5b | · · · · **ba** 5b | · · · · **5b** **6a** |
| 1 | · · · · · · | · · · · · · | · · · · · · |
| 2 | · 7a · 2a · · | · 7a · **2q** · · | · 7a · 2a · · |
| 3 | · 2F · · 3i · | · **1F** · · **31** · | · 2F · · 3i · |
| 4 | · 3c · · · · | · 3c · · · · | · 3c · · · · |
| 5 | · · · · · · | · · · · · · | · · · · · · |
| 6 | · · · · · · | · · · · · · | · · · · · · |
| 7 | · · · · · x | · · · · · x | · · · · · x |
