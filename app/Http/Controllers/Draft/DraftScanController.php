<?php

namespace App\Http\Controllers\Draft;

use App\Http\Controllers\Controller;
use App\Services\Analytics\Sources\DummyDowntimeSource;
use App\Services\Audit\AuditLog;
use App\Services\Scan\E6ResultAdapter;
use App\Services\Scan\LayananE6;
use App\Services\Scan\ScanStore;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DRAFT "Tambah Data" dengan AI Baca Kertas — Eksplorasi 6 (K7), tanpa login
 * & tanpa database. Foto SUNGGUHAN dibaca layanan Python E6 (mode python)
 * atau diputar ulang dari hasil E6 tersimpan (mode contoh); hasil akhir
 * disimpan ke riwayat scan (file), BELUM ke MFDOWNTIME.
 *
 * Di halaman asli: petugas = auth()->user()->UserCode, dan simpan() juga
 * menulis baris ke MFDOWNTIME lewat logika PaperScanController::store().
 *
 * Peran disimulasikan: petugas (default, "PRD01") mengunggah & meninjau;
 * admin (?peran=admin) hanya memantau semua scan (di aplikasi asli admin
 * dialihkan dari halaman scan).
 */
class DraftScanController extends Controller
{
    private const PETUGAS_CONTOH = 'PRD01';

    private const SUMBER_FOTO = ['kamera_hp' => 'Kamera HP', 'kamera_pc' => 'Kamera komputer', 'file' => 'Pilih file'];

    public function tambah(Request $request)
    {
        $isAdmin = $this->isAdmin($request);

        return response()->view('draft.scan.tambah', [
            'isAdmin' => $isAdmin,
            'layanan' => LayananE6::make()->info(),
            'antrian' => array_slice(ScanStore::make()->daftar(self::PETUGAS_CONTOH, 5), 0, 5),
        ], $isAdmin ? 403 : 200);
    }

    public function unggah(Request $request)
    {
        abort_if($this->isAdmin($request), 403, 'Admin tidak menginput data.');
        $request->validate([
            'foto' => ['required', 'array', 'min:1', 'max:10'],
            'foto.*' => ['required', 'image', 'max:20480'],
            'sumber' => ['required', 'in:'.implode(',', array_keys(self::SUMBER_FOTO))],
        ], [
            'foto.required' => 'Pilih atau ambil foto formulir dulu.',
            'foto.*.image' => 'File harus berupa foto (JPG/PNG).',
            'foto.*.max' => 'Ukuran foto maksimal 20 MB.',
        ]);

        $store = ScanStore::make();
        $layanan = LayananE6::make();
        $ids = [];
        foreach ($request->file('foto') as $foto) {
            $status = $store->buat($foto, self::PETUGAS_CONTOH, $request->input('sumber'));
            $store->ubahStatus($status['id'], ['mode' => $layanan->mode()]);
            $ids[] = $status['id'];
        }
        $hidup = $layanan->pastikanBerjalan();

        return redirect()->route('draft.scan')->with('status', count($ids).' foto masuk antrian. Silakan lanjut bekerja — Anda akan diberi tahu saat selesai diproses.')
            ->with('gagal_layanan', $hidup ? null : 'Layanan Python E6 tidak bisa dinyalakan. Cek E6_PYTHON_BIN di .env dan storage/logs/layanan-e6.log.');
    }

    public function antrian(Request $request)
    {
        $isAdmin = $this->isAdmin($request);
        $layanan = LayananE6::make();
        $daftar = array_map(fn ($s) => $layanan->majukanContoh($s), ScanStore::make()->daftar($isAdmin ? null : self::PETUGAS_CONTOH, 100));

        return view('draft.scan.antrian', [
            'isAdmin' => $isAdmin,
            'daftar' => $daftar,
            'layanan' => $layanan->info(),
            'sumberFoto' => self::SUMBER_FOTO,
        ]);
    }

    /**
     * Polling dari semua halaman draft (scan-notif.js): status antrian milik
     * petugas + scan yang baru selesai & belum diberitahukan.
     */
    public function status(Request $request): JsonResponse
    {
        if ($this->isAdmin($request)) {
            return response()->json(['berjalan' => 0, 'baru_selesai' => []]);
        }
        $layanan = LayananE6::make();
        $daftar = array_map(fn ($s) => $layanan->majukanContoh($s), ScanStore::make()->daftar(self::PETUGAS_CONTOH, 30, now('Asia/Jakarta')->subDays(2)));
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
                'url' => route('draft.scan.tinjau', $s['id']),
            ], $baru),
            'layanan' => array_intersect_key($layanan->info(), array_flip(['hidup', 'mode', 'device', 'model_dimuat', 'galat'])),
        ]);
    }

    public function diberitahu(Request $request, string $id): JsonResponse
    {
        $this->pastikanId($id);
        ScanStore::make()->ubahStatus($id, ['diberitahu' => true]);

        return response()->json(['ok' => true]);
    }

    public function ulang(Request $request, string $id)
    {
        abort_if($this->isAdmin($request), 403);
        $store = $this->pastikanId($id);
        $s = $store->status($id);
        abort_unless(in_array($s['status'], ['gagal', 'perlu_foto_ulang'], true) && is_file($store->folder($id).'/foto.jpg'), 422, 'Scan ini tidak bisa diproses ulang.');
        $layanan = LayananE6::make();
        $store->ubahStatus($id, ['status' => 'menunggu', 'mode' => $layanan->mode(), 'dibuat' => now('Asia/Jakarta')->toIso8601String(),
            'mulai' => null, 'selesai' => null, 'pesan' => null, 'diberitahu' => false]);
        $layanan->pastikanBerjalan();

        return redirect()->route('draft.scan')->with('status', 'Foto dimasukkan lagi ke antrian.');
    }

    public function tinjau(Request $request, string $id)
    {
        $store = $this->pastikanId($id);
        $isAdmin = $this->isAdmin($request);
        $status = LayananE6::make()->majukanContoh($store->status($id));
        abort_if(! $isAdmin && $status['petugas'] !== self::PETUGAS_CONTOH, 403);
        if (empty($status['diberitahu']) && in_array($status['status'], ScanStore::SELESAI, true)) {
            $status = $store->ubahStatus($id, ['diberitahu' => true]);
        }

        $source = new DummyDowntimeSource();
        $hasil = $store->hasilAi($id);
        $tinjauan = $hasil && ($hasil['data'] ?? null) ? $this->adapter($source)->untukTinjauan($hasil) : null;
        $final = $store->final($id);

        $awal = null;
        if ($tinjauan) {
            $h = $tinjauan['header'];
            $mesin = $source->mesin();
            $item = $source->itemList();
            $size = $h['size']['ai'] ? (float) preg_replace('/[^0-9.]/', '', $h['size']['ai']) : null;
            $awal = $final['header'] ?? [
                'tanggal' => E6ResultAdapter::tanggalIso($h['tanggal']['ai'], $h['tanggal']['iso']),
                'mesin' => E6ResultAdapter::cocokkanMesin($h['mesin']['ai'], array_keys($mesin)),
                'shift' => $h['shift']['ai'],
                'nik' => null, // operator tidak andal (E6: 3/7) → selalu dipilih petugas
                'speed' => $h['speed']['ai'],
                'itemno' => $size ? collect($item)->search(fn ($n) => abs((float) preg_replace('/[^0-9.]/', '', $n) - $size) < 0.001) ?: null : null,
            ];
        }

        return view('draft.scan.tinjau', [
            'isAdmin' => $isAdmin,
            'status' => $status,
            'tinjauan' => $tinjauan,
            'final' => $final,
            'awal' => $awal,
            'opsiMesin' => $source->mesin(),
            'opsiOperator' => $source->operatorList(),
            'opsiItem' => $source->itemList(),
            'kategori' => $source->kategori(),
            'sumberFoto' => self::SUMBER_FOTO,
        ]);
    }

    /** Baris downtime dari grid + header saat ini (dipanggil ulang tiap petugas mengubah kotak). */
    public function pratinjau(Request $request, string $id): JsonResponse
    {
        $store = $this->pastikanId($id);
        [$grid, $jam, $header] = $this->inputTinjauan($request, $store, $id);

        return response()->json(['rows' => $this->adapter(new DummyDowntimeSource())->keBaris($grid, $jam, $header)]);
    }

    public function simpan(Request $request, string $id)
    {
        abort_if($this->isAdmin($request), 403, 'Admin tidak menginput data.');
        $store = $this->pastikanId($id);
        $status = $store->status($id);
        abort_unless($status['status'] === 'selesai', 422, 'Scan ini belum siap atau sudah disimpan.');

        $source = new DummyDowntimeSource();
        $request->validate([
            'header.tanggal' => ['required', 'date'],
            'header.mesin' => ['required', 'in:'.implode(',', array_keys($source->mesin()))],
            'header.nik' => ['required', 'in:'.implode(',', array_keys($source->operatorList()))],
            'header.speed' => ['required', 'numeric', 'min:0'],
            'header.itemno' => ['nullable', 'in:'.implode(',', array_keys($source->itemList()))],
        ], [], [
            'header.tanggal' => 'Tanggal', 'header.mesin' => 'Mesin', 'header.nik' => 'Operator',
            'header.speed' => 'Speed', 'header.itemno' => 'Produk',
        ]);

        [$grid, $jam, $header] = $this->inputTinjauan($request, $store, $id);
        $adapter = $this->adapter($source);
        $rows = $adapter->keBaris($grid, $jam, $header);
        $bermasalah = array_filter($rows, fn ($r) => $r['_alasan'] !== []);
        if ($bermasalah) {
            return back()->withInput()->withErrors(['grid' => count($bermasalah).' kejadian masih bermasalah (kode tidak lengkap/tidak dikenal). Perbaiki kotak yang ditandai merah dulu.']);
        }

        $tinjauan = $adapter->untukTinjauan($store->hasilAi($id));
        $koreksi = $adapter->koreksi($tinjauan, $grid);
        $waktu = now('Asia/Jakarta');
        $store->simpanFinal($id, [
            'disimpan_oleh' => self::PETUGAS_CONTOH,
            'disimpan_pada' => $waktu->toIso8601String(),
            'header' => $header,
            'grid' => $grid,
            'rows' => array_map(fn ($r) => array_diff_key($r, ['_alasan' => 1]), $rows),
            'koreksi' => $koreksi,
            'detik_tinjau' => (int) $request->input('detik_tinjau') ?: null,
        ]);
        $store->ubahStatus($id, ['status' => 'disimpan', 'disimpan_pada' => $waktu->toIso8601String(), 'n_baris' => count($rows)]);
        AuditLog::make()->catat('scan.simpan', self::PETUGAS_CONTOH, $id, null, [
            'mesin' => $header['mesin'], 'tanggal' => $header['tanggal'], 'baris' => count($rows),
            'kotak_dikoreksi' => $koreksi['dikoreksi'],
        ], $request->ip());

        return redirect()->route('draft.scan')->with('status', count($rows).' kejadian downtime disimpan (draft: ke riwayat scan, belum ke database). '.$koreksi['dikoreksi'].' kotak Anda koreksi dari hasil AI.');
    }

    public function berkas(string $id, string $nama)
    {
        $path = $this->pastikanId($id)->berkas($id, $nama);
        abort_if($path === null, 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400']);
    }

    // ------------------------------------------------------------------

    private function isAdmin(Request $request): bool
    {
        return $request->input('peran') === 'admin';
    }

    private function pastikanId(string $id): ScanStore
    {
        abort_unless(ScanStore::idValid($id), 404);
        $store = ScanStore::make();
        abort_if($store->status($id) === null, 404);

        return $store;
    }

    private function adapter(DummyDowntimeSource $source): E6ResultAdapter
    {
        return new E6ResultAdapter(fn (string $p, string $d) => $source->problemDesc($p, $d));
    }

    /**
     * Grid kode dari form ("grid[1][0][]" = 6 kode blok jam pertama shift 1);
     * label jam diambil dari hasil AI (bukan dari input) supaya tidak bisa diubah.
     *
     * @return array{0: array, 1: array, 2: array}
     */
    private function inputTinjauan(Request $request, ScanStore $store, string $id): array
    {
        $tinjauan = $this->adapter(new DummyDowntimeSource())->untukTinjauan($store->hasilAi($id) ?? []);
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
            'itemno' => $h['itemno'] ?? null,
        ];

        return [$grid, $jam, $header];
    }
}
