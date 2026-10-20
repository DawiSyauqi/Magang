<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLog;
use App\Services\Scan\E6ResultAdapter;
use App\Services\Scan\LayananE6;
use App\Services\Scan\PencocokHeader;
use App\Services\Scan\ScanStore;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tambah Data dengan AI Baca Kertas — Eksplorasi 6 (K7), dipakai bersama oleh:
 *  - Draft\DraftScanController : /draft — data contoh, hasil disimpan ke file
 *  - Panel\AppScanController   : aplikasi asli — master dari database, hasil
 *                                disimpan ke MFDOWNTIME
 *
 * Alur: foto (kamera HP / kamera komputer / file) → antrian file
 * storage/app/scan-history → layanan Python E6 di latar belakang →
 * pemberitahuan "Selesai diproses" → tinjau kotak ragu → simpan.
 * Petugas mengunggah & meninjau; admin hanya memantau (Riwayat Scan).
 */
abstract class ScanController extends Controller
{
    protected const SUMBER_FOTO = ['kamera_hp' => 'Kamera HP', 'kamera_pc' => 'Kamera komputer', 'file' => 'Pilih file'];

    // ------------------------------------------------------------------
    // Titik sambung mode draft / asli
    // ------------------------------------------------------------------

    abstract protected function isAdmin(Request $request): bool;

    /** Kode petugas pemilik scan (draft: 'PRD01'; asli: UserCode login). */
    abstract protected function petugas(Request $request): string;

    /** Awalan nama rute: 'draft.' atau ''. */
    abstract protected function rp(): string;

    /** Nama rute halaman unggah foto ('draft.tambah' / 'tambah.foto'). */
    abstract protected function ruteFoto(): string;

    /** kode => nama mesin. */
    abstract protected function opsiMesin(): array;

    /** NIK => nama operator. */
    abstract protected function opsiOperator(): array;

    /** ITEMNO => nama item (untuk mesin tertentu bila didukung). */
    abstract protected function opsiItem(?string $mesin): array;

    /** ProblemCode + ProblemCodeD → teks detail masalah (MFPROBLEMD). */
    abstract protected function problemDesc(string $problemCode, string $problemCodeD): ?string;

    /** kode => nama kategori masalah. */
    abstract protected function kategori(): array;

    /**
     * Simpan hasil tinjauan. Mengembalikan redirect bila ditolak (validasi),
     * atau array [pesan, daftar No_Trs] bila berhasil.
     *
     * @return RedirectResponse|array{0: string, 1: string[]}
     */
    abstract protected function simpanBaris(Request $request, string $id, array $header, array $rows): RedirectResponse|array;

    protected function peranQ(Request $request): array
    {
        return [];
    }

    /** Pencocok tulisan mesin/operator (asli: memakai & menambah alias mesin). */
    protected function pencocok(): PencocokHeader
    {
        return new PencocokHeader();
    }

    protected function tampil(string $view, array $data = [], int $status = 200)
    {
        return response()->view('panel.scan.'.$view, $data, $status);
    }

    // ------------------------------------------------------------------

    public function tambah(Request $request)
    {
        $isAdmin = $this->isAdmin($request);

        return $this->tampil('tambah', [
            'shiftSekarang' => $this->shiftSekarang(),
            'layanan' => LayananE6::make()->info(),
            'antrian' => array_slice(ScanStore::make()->daftar($this->petugas($request), 5), 0, 5),
        ], $isAdmin ? 403 : 200);
    }

    public function unggah(Request $request)
    {
        abort_if($this->isAdmin($request), 403, 'Admin tidak menginput data.');
        $request->validate([
            'foto' => ['required', 'array', 'min:1', 'max:10'],
            'foto.*' => ['required', 'image', 'max:20480'],
            'sumber' => ['required', 'in:'.implode(',', array_keys(self::SUMBER_FOTO))],
            'shift' => ['required', 'in:1,2,3,semua'],
        ], [
            'foto.required' => 'Pilih atau ambil foto formulir dulu.',
            'foto.*.image' => 'File harus berupa foto (JPG/PNG).',
            'foto.*.max' => 'Ukuran foto maksimal 20 MB.',
        ]);

        $store = ScanStore::make();
        $layanan = LayananE6::make();
        $ids = [];
        foreach ($request->file('foto') as $foto) {
            $status = $store->buat($foto, $this->petugas($request), $request->input('sumber'), $this->opsiBaca($request->input('shift'), false));
            $store->ubahStatus($status['id'], ['mode' => $layanan->mode()]);
            $ids[] = $status['id'];
        }
        $hidup = $layanan->pastikanBerjalan();

        return redirect()->route($this->rp().'scan', $this->peranQ($request))->with('status', count($ids).' foto masuk antrian. Silakan lanjut bekerja — Anda akan diberi tahu saat selesai diproses.')
            ->with('gagal_layanan', $hidup ? null : 'Layanan Python E6 tidak bisa dinyalakan. Cek E6_PYTHON_BIN di .env dan storage/logs/layanan-e6.log.');
    }

    public function antrian(Request $request)
    {
        $isAdmin = $this->isAdmin($request);
        $layanan = LayananE6::make();
        $daftar = array_map(fn ($s) => $layanan->majukanContoh($s), ScanStore::make()->daftar($isAdmin ? null : $this->petugas($request), 100));

        return $this->tampil('antrian', [
            'daftar' => $daftar,
            'layanan' => $layanan->info(),
            'sumberFoto' => self::SUMBER_FOTO,
        ]);
    }

    /**
     * Polling dari semua halaman (scan-notif.js): status antrian milik petugas
     * + scan yang baru selesai & belum diberitahukan.
     */
    public function status(Request $request): JsonResponse
    {
        if ($this->isAdmin($request)) {
            return response()->json(['berjalan' => 0, 'baru_selesai' => []]);
        }
        $layanan = LayananE6::make();
        $daftar = array_map(fn ($s) => $layanan->majukanContoh($s), ScanStore::make()->daftar($this->petugas($request), 30, now('Asia/Jakarta')->subDays(2)));
        $berjalan = array_values(array_filter($daftar, fn ($s) => in_array($s['status'], ['menunggu', 'diproses'], true)));
        $baru = array_values(array_filter($daftar, fn ($s) => in_array($s['status'], ['selesai', 'perlu_foto_ulang', 'gagal'], true) && empty($s['diberitahu'])));
        if ($berjalan) {
            // Layanan bisa mati di tengah antrian (ditutup paksa, PC restart):
            // nyalakan lagi dari polling supaya foto tidak menunggu selamanya.
            $layanan->pastikanBerjalan();
        }

        return response()->json([
            'berjalan' => count($berjalan),
            'antrian' => array_map(fn ($s) => ['id' => $s['id'], 'status' => $s['status']], $berjalan),
            'baru_selesai' => array_map(fn ($s) => [
                'id' => $s['id'],
                'status' => $s['status'],
                'pesan' => $s['status'] === 'gagal' ? 'Kendala sistem — coba proses ulang atau hubungi admin.' : LayananE6::pesan($s),
                'n_ragu' => $s['n_kotak_ragu'] ?? null,
                'url' => route($this->rp().'scan.tinjau', $s['id']),
            ], $baru),
            'layanan' => array_intersect_key($layanan->info(), array_flip(['hidup', 'mode', 'device', 'model_dimuat', 'galat'])),
        ]);
    }

    public function diberitahu(Request $request, string $id): JsonResponse
    {
        $this->pastikanMilik($request, $id);
        ScanStore::make()->ubahStatus($id, ['diberitahu' => true]);

        return response()->json(['ok' => true]);
    }

    public function ulang(Request $request, string $id)
    {
        abort_if($this->isAdmin($request), 403);
        $store = $this->pastikanMilik($request, $id);
        $s = $store->status($id);
        // Gagal / perlu foto ulang: proses ulang biasa. Selesai (belum disimpan): baca ulang dengan shift lain.
        abort_unless(in_array($s['status'], ['gagal', 'perlu_foto_ulang', 'selesai'], true) && is_file($store->folder($id).'/foto.jpg'), 422, 'Scan ini tidak bisa diproses ulang.');
        $shift = in_array($request->input('shift'), ['1', '2', '3', 'semua'], true) ? $request->input('shift') : null;
        $layanan = LayananE6::make();
        $store->ubahStatus($id, ['status' => 'menunggu', 'mode' => $layanan->mode(), 'dibuat' => now('Asia/Jakarta')->toIso8601String(),
            'mulai' => null, 'selesai' => null, 'pesan' => null, 'diberitahu' => false, 'shift_dipakai' => null, 'shift_sumber' => null] + ($shift ? $this->opsiBaca($shift, true) : []));
        $layanan->pastikanBerjalan();

        return redirect()->route($this->rp().'scan')->with('status', 'Foto dimasukkan lagi ke antrian.');
    }

    /**
     * Hapus scan dari antrian (salah foto, foto ganda, gagal). Yang sedang dibaca
     * layanan tidak boleh dihapus; yang sudah disimpan juga tidak, karena menjadi
     * bahan Statistik AI dan data downtime-nya sudah ada di MFDOWNTIME.
     */
    public function hapus(Request $request, string $id)
    {
        abort_if($this->isAdmin($request), 403);
        $store = $this->pastikanMilik($request, $id);
        $s = $store->status($id);
        if (in_array($s['status'], ['diproses', 'disimpan'], true)) {
            return redirect()->route($this->rp().'scan')->withErrors(['hapus' => $s['status'] === 'diproses'
                ? 'Foto sedang dibaca AI, tunggu sampai selesai lalu hapus.'
                : 'Scan yang sudah disimpan tidak bisa dihapus dari riwayat.']);
        }
        // Cek ulang tepat sebelum hapus: layanan bisa saja baru mengambil foto ini.
        if (($store->status($id)['status'] ?? null) === 'diproses' || ! $store->hapus($id)) {
            return redirect()->route($this->rp().'scan')->withErrors(['hapus' => 'Foto sedang dipakai layanan AI, coba hapus lagi sebentar lagi.']);
        }
        AuditLog::make()->catat('scan.hapus', $this->petugas($request), $id, null, [
            'status' => $s['status'], 'nama_asli' => $s['nama_asli'] ?? null,
        ], $request->ip());

        return redirect()->route($this->rp().'scan')->with('status', 'Scan '.($s['nama_asli'] ?? $id).' dihapus dari antrian.');
    }

    public function tinjau(Request $request, string $id)
    {
        $store = $this->pastikanMilik($request, $id);
        $status = LayananE6::make()->majukanContoh($store->status($id));
        if (empty($status['diberitahu']) && in_array($status['status'], ScanStore::SELESAI, true)) {
            $status = $store->ubahStatus($id, ['diberitahu' => true]);
        }

        $hasil = $store->hasilAi($id);
        $tinjauan = $hasil && ($hasil['data'] ?? null) ? $this->adapter()->untukTinjauan($hasil) : null;
        $final = $store->final($id);

        $awal = null;
        $usulan = ['mesin' => null, 'operator' => null];
        if ($tinjauan) {
            $h = $tinjauan['header'];
            $pencocok = $this->pencocok();
            $usulan['mesin'] = $pencocok->mesin($h['mesin']['ai'], $this->opsiMesin());
            $usulan['operator'] = $pencocok->operator($h['operator']['ai'] ?? null, $this->opsiOperator());
            $mesinAwal = $final['header']['mesin'] ?? $usulan['mesin']['kode'];
            $size = $h['size']['ai'] ? (float) preg_replace('/[^0-9.]/', '', str_replace(',', '.', $h['size']['ai'])) : null;
            $awal = $final['header'] ?? [
                'tanggal' => E6ResultAdapter::tanggalIso($h['tanggal']['ai'], $h['tanggal']['iso']),
                'mesin' => $mesinAwal,
                // Shift yang benar-benar dibaca AI (dipaksa > tulisan di kertas > pilihan saat unggah).
                'shift' => $hasil['data']['shift_dipakai'] ?? $status['shift_paksa'] ?? $h['shift']['ai'],
                // Nama operator kurang andal (E6: 3/7) → hanya usulan yang cukup mirip yang dipilihkan; tetap wajib diperiksa.
                'nik' => $usulan['operator']['yakin'] ? $usulan['operator']['nik'] : null,
                'speed' => $h['speed']['ai'],
                'itemno' => $size ? $this->cocokkanItem($this->opsiItem($mesinAwal), $size) : null,
            ];
        }

        return $this->tampil('tinjau', [
            'status' => $status,
            'tinjauan' => $tinjauan,
            'final' => $final,
            'awal' => $awal,
            'usulan' => $usulan,
            'opsiMesin' => $this->opsiMesin(),
            'opsiOperator' => $this->opsiOperator(),
            'opsiItem' => $this->opsiItem($awal['mesin'] ?? null),
            'kategori' => $this->kategori(),
            'sumberFoto' => self::SUMBER_FOTO,
            'temuan' => (array) session('validasi_temuan', []),
            // Diminta 1 shift tetapi hasil AI tidak mencatat mode "shift" → layanan E6 masih kode lama.
            'layananLama' => $tinjauan && ($status['baris'] ?? null) === 'shift'
                && (($hasil['meta']['baris_dipakai'] ?? null) !== 'shift'),
        ]);
    }

    /** Baris downtime dari grid + header saat ini (dipanggil ulang tiap petugas mengubah kotak). */
    public function pratinjau(Request $request, string $id): JsonResponse
    {
        $store = $this->pastikanMilik($request, $id);
        [$grid, $jam, $header] = $this->inputTinjauan($request, $store, $id);

        return response()->json(['rows' => $this->adapter()->keBaris($grid, $jam, $header)]);
    }

    public function simpan(Request $request, string $id)
    {
        abort_if($this->isAdmin($request), 403, 'Admin tidak menginput data.');
        $store = $this->pastikanMilik($request, $id);
        $status = $store->status($id);
        abort_unless($status['status'] === 'selesai', 422, 'Scan ini belum siap atau sudah disimpan.');

        $request->validate([
            'header.tanggal' => ['required', 'date', 'before_or_equal:today'],
            'header.mesin' => ['required', 'in:'.implode(',', array_keys($this->opsiMesin()))],
            'header.nik' => ['required', 'in:'.implode(',', array_keys($this->opsiOperator()))],
            'header.speed' => ['required', 'numeric', 'min:0'],
            'header.itemno' => ['nullable', 'string', 'max:30'],
        ], [], [
            'header.tanggal' => 'Tanggal', 'header.mesin' => 'Mesin', 'header.nik' => 'Operator',
            'header.speed' => 'Speed', 'header.itemno' => 'Produk',
        ]);

        [$grid, $jam, $header] = $this->inputTinjauan($request, $store, $id);
        $adapter = $this->adapter();
        $rows = $adapter->keBaris($grid, $jam, $header);
        if (! $rows) {
            return back()->withInput()->withErrors(['grid' => 'Tidak ada kejadian downtime di kotak Lost time. Periksa lagi grid atau abaikan foto ini.']);
        }
        $bermasalah = array_filter($rows, fn ($r) => $r['_alasan'] !== []);
        if ($bermasalah) {
            return back()->withInput()->withErrors(['grid' => count($bermasalah).' kejadian masih bermasalah (kode tidak lengkap/tidak dikenal). Perbaiki kotak yang ditandai merah dulu.']);
        }

        $hasil = $this->simpanBaris($request, $id, $header, $rows);
        if ($hasil instanceof RedirectResponse) {
            return $hasil;
        }
        [$pesan, $noTrs] = $hasil;

        $tinjauan = $adapter->untukTinjauan($store->hasilAi($id));
        // Petugas memastikan mesin: ingat tulisan di kertas → kode mesin sebagai alias
        // (pengganti konfirmasi alias alur lama), supaya lembar berikutnya langsung cocok.
        $tulisanMesin = $tinjauan['header']['mesin']['ai'] ?? null;
        if ($header['mesin'] && $this->pencocok()->ingat($tulisanMesin, $header['mesin'])) {
            AuditLog::make()->catat('alias.konfirmasi', $this->petugas($request), trim((string) $tulisanMesin), null, ['resrceno' => $header['mesin'], 'dari' => 'scan '.$id], $request->ip());
            $pesan .= ' Tulisan mesin "'.trim((string) $tulisanMesin).'" kini dikenali sebagai '.$header['mesin'].'.';
        }
        $koreksi = $adapter->koreksi($tinjauan, $grid);
        $waktu = now('Asia/Jakarta');
        $store->simpanFinal($id, [
            'disimpan_oleh' => $this->petugas($request),
            'disimpan_pada' => $waktu->toIso8601String(),
            'header' => $header,
            'grid' => $grid,
            'rows' => array_map(fn ($r) => array_diff_key($r, ['_alasan' => 1]), $rows),
            'no_trs' => $noTrs,
            'koreksi' => $koreksi,
            'detik_tinjau' => (int) $request->input('detik_tinjau') ?: null,
        ]);
        $store->ubahStatus($id, ['status' => 'disimpan', 'disimpan_pada' => $waktu->toIso8601String(), 'n_baris' => count($rows), 'no_trs' => $noTrs]);

        return redirect()->route($this->rp().'scan')->with('status', $pesan.' '.$koreksi['dikoreksi'].' kotak Anda koreksi dari hasil AI.');
    }

    public function berkas(Request $request, string $id, string $nama)
    {
        $path = $this->pastikanMilik($request, $id)->berkas($id, $nama);
        abort_if($path === null, 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400']);
    }

    // ------------------------------------------------------------------

    /** Shift yang sedang berjalan menurut jam komputer server (jadwal config produksi.shift). */
    protected function shiftSekarang(): string
    {
        $sekarang = now('Asia/Jakarta');
        $m = $sekarang->hour * 60 + $sekarang->minute;
        foreach (config('produksi.shift') as $s => $j) {
            [$a, $b] = array_map(fn ($t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2), [$j['mulai'], $j['selesai']]);
            if ($a < $b ? ($m >= $a && $m < $b) : ($m >= $a || $m < $b)) {
                return (string) $s;
            }
        }

        return '1';
    }

    /**
     * Pilihan baca untuk layanan E6. Saat unggah, shift yang dipilih hanya CADANGAN:
     * AI membaca baris sesuai tulisan shift di kertas, dan memakai pilihan ini bila
     * tulisan itu tidak terbaca. Saat "Baca ulang", shift yang dipilih DIPAKSA
     * (untuk memperbaiki tulisan shift yang salah dibaca AI).
     */
    protected function opsiBaca(?string $shift, bool $paksa): array
    {
        if (! in_array($shift, ['1', '2', '3'], true)) {
            return ['shift_paksa' => null, 'baris' => 'bertinta'];
        }

        return $paksa
            ? ['shift_paksa' => $shift, 'baris' => 'shift']
            : ['shift_paksa' => null, 'shift_cadangan' => $shift, 'baris' => 'shift'];
    }

    /** Scan harus ada; petugas hanya boleh membuka scan miliknya (admin: semua). */
    protected function pastikanMilik(Request $request, string $id): ScanStore
    {
        abort_unless(ScanStore::idValid($id), 404);
        $store = ScanStore::make();
        $status = $store->status($id);
        abort_if($status === null, 404);
        abort_if(! $this->isAdmin($request) && ($status['petugas'] ?? null) !== $this->petugas($request), 403);

        return $store;
    }

    protected function adapter(): E6ResultAdapter
    {
        return new E6ResultAdapter(fn (string $p, string $d) => $this->problemDesc($p, $d));
    }

    /** Item yang angka ukurannya (mis. "DIA.04.00 MM") sama dengan Size di formulir. */
    protected function cocokkanItem(array $item, float $size): ?string
    {
        foreach ($item as $kode => $nama) {
            preg_match_all('/\d+(?:[.,]\d+)*/', (string) $nama, $m);
            foreach ($m[0] as $angka) {
                $bagian = preg_split('/[.,]/', $angka);
                $nilai = count($bagian) > 1 ? (float) ($bagian[0].'.'.implode('', array_slice($bagian, 1))) : (float) $angka;
                if (abs($nilai - $size) < 0.001) {
                    return (string) $kode;
                }
            }
        }

        return null;
    }

    /**
     * Grid kode dari form ("grid[1][0][]" = 6 kode blok jam pertama shift 1);
     * label jam diambil dari hasil AI (bukan dari input) supaya tidak bisa diubah.
     *
     * @return array{0: array, 1: array, 2: array}
     */
    protected function inputTinjauan(Request $request, ScanStore $store, string $id): array
    {
        $tinjauan = $this->adapter()->untukTinjauan($store->hasilAi($id) ?? []);
        $grid = $jam = [];
        $masuk = (array) $request->input('grid', []);
        foreach ($tinjauan['baris'] as $shift => $row) {
            if (! empty($request->input("abaikan.{$shift}"))) {
                continue; // baris shift ini dinyatakan bukan bagian formulir
            }
            foreach ($row['blok'] as $b => $blok) {
                $jam[$shift][$b] = $blok['jam'];
                $kode = array_values((array) ($masuk[$shift][$b] ?? array_column($blok['kotak'], 'kode')));
                $grid[$shift][$b] = array_map(fn ($k) => is_string($k) && trim($k) !== '' ? strtolower(substr(trim($k), 0, 3)) : null, array_pad(array_slice($kode, 0, 6), 6, null));
            }
        }
        $h = (array) $request->input('header', []);
        $header = [
            'tanggal' => isset($h['tanggal']) && strtotime($h['tanggal']) ? Carbon::parse($h['tanggal'])->format('Y-m-d') : null,
            'shift' => in_array($h['shift'] ?? null, ['1', '2', '3'], true) ? $h['shift'] : null,
            'mesin' => $h['mesin'] ?? null,
            'nik' => $h['nik'] ?? null,
            'speed' => $h['speed'] ?? null,
            'itemno' => ($h['itemno'] ?? null) ?: null,
        ];

        return [$grid, $jam, $header];
    }
}
