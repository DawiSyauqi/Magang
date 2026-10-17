<?php

namespace App\Services\Scan;

use App\Services\PaperExtraction\GridTimeMerger;
use App\Services\PaperExtraction\ProblemCodeConverter;
use App\Services\PaperExtraction\ProblemCodeResolver;
use Carbon\Carbon;

/**
 * Envelope Eksplorasi 6 (baca_fullpage_eksplorasi.py) → bentuk yang dipakai
 * layar tinjauan, lalu → baris draft berbentuk kolom MFDOWNTIME.
 *
 * Penggabungan kotak 10 menit menjadi kejadian memakai GridTimeMerger yang
 * SAMA dengan alur lama (protokol kode berulang + penanda 'x'), dan kode
 * kertas (mis. "3b") diubah lewat ProblemCodeResolver yang sama — lookup
 * MFPROBLEMD di-inject, jadi draft bisa memakai daftar contoh tanpa database.
 */
class E6ResultAdapter
{
    /** Field header yang ditampilkan di layar tinjauan, berurutan. */
    public const FIELD = ['tanggal', 'mesin', 'shift', 'operator', 'size', 'speed'];

    protected GridTimeMerger $merger;

    protected ProblemCodeResolver $resolver;

    /**
     * @param  callable(string $problemCode, string $problemCodeD): ?string  $lookupProblemDesc
     */
    public function __construct(callable $lookupProblemDesc)
    {
        $converter = new ProblemCodeConverter();
        $this->merger = new GridTimeMerger($converter);
        $this->resolver = new ProblemCodeResolver($converter, $lookupProblemDesc);
    }

    /**
     * @return array{header: array, baris: array, n_ragu: int, n_berisi: int, shift_header: ?string, meta: array}
     */
    public function untukTinjauan(array $env): array
    {
        $data = $env['data'] ?? [];
        $header = [];
        foreach (self::FIELD as $f) {
            $h = $data['header'][$f] ?? ['nilai' => null, 'kosong' => true];
            $header[$f] = [
                'ai' => $h['nilai'] ?? null,
                'kosong' => (bool) ($h['kosong'] ?? false),
                'crop' => empty($h['kosong']) ? "foto_field_{$f}.png" : null,
                'iso' => $h['iso'] ?? null,
                // Field yang perlu dicek: shift dgn keyakinan rendah, tanggal tak valid,
                // operator selalu (uji E6: operator hanya 3/7 benar).
                'cek' => match ($f) {
                    'shift' => ($h['keyakinan'] ?? 1) < 0.85,
                    'tanggal' => ! ($h['valid'] ?? false) || ! ($h['hari_cocok_kalender'] ?? true),
                    'operator' => ! ($h['kosong'] ?? false),
                    default => false,
                },
            ];
        }

        $baris = [];
        $nRagu = $nBerisi = 0;
        foreach ($data['lost_time'] ?? [] as $shift => $row) {
            $blok = [];
            foreach ($row['blocks'] ?? [] as $b) {
                $calls = collect($b['calls'])->keyBy('kolom');
                $kotak = [];
                foreach ($b['kotak'] as $i => $kode) {
                    $c = $calls->get($i + 1);
                    $kotak[] = [
                        'kode' => $kode === null ? null : strtolower($kode),
                        'ragu' => (bool) ($c['ragu'] ?? false),
                        'yakin' => $c['keyakinan'] ?? null,
                        'kandidat' => $c['kandidat_3'] ?? [],
                        'crop' => $kode === null ? null : "foto_s{$shift}_b{$b['block_idx']}_k".($i + 1).'.jpg',
                    ];
                    $nBerisi += $kode !== null ? 1 : 0;
                    $nRagu += ($c['ragu'] ?? false) ? 1 : 0;
                }
                $blok[] = ['jam' => $b['jam'], 'kotak' => $kotak];
            }
            $baris[(string) $shift] = [
                'status' => $row['status'] ?? 'success',
                'alasan' => $row['reason'] ?? null,
                'blok' => $blok,
            ];
        }
        ksort($baris);

        return [
            'header' => $header,
            'baris' => $baris,
            'n_ragu' => $nRagu,
            'n_berisi' => $nBerisi,
            'shift_header' => $data['shift_header'] ?? null,
            'meta' => $env['meta'] ?? [],
        ];
    }

    /**
     * Grid kode (hasil tinjauan) → baris draft MFDOWNTIME.
     *
     * @param  array<string, array<int, array<int, ?string>>>  $grid  [shift => [blokIdx => [6 kode|null]]]
     * @param  array<string, array<int, string>>  $jam  [shift => [blokIdx => "07.00 - 08.00"]]
     * @param  array{tanggal: ?string, mesin: ?string, nik: ?string, speed: mixed, itemno: ?string}  $header
     */
    public function keBaris(array $grid, array $jam, array $header): array
    {
        $rows = [];
        foreach ($grid as $shift => $blokList) {
            $gridWaktu = [];
            foreach ($blokList as $i => $kotak) {
                $gridWaktu[] = ['jam_mulai' => $jam[$shift][$i] ?? '', 'blok' => array_map(fn ($k) => $k === null || $k === '' ? null : strtolower(trim($k)), $kotak)];
            }
            foreach ($this->merger->merge($gridWaktu) as $e) {
                $r = $this->resolver->resolve($e['raw_code']);
                [$mulai, $selesai] = $this->waktu($header['tanggal'] ?? null, $e);
                $alasan = array_values(array_filter([
                    $r['alasan'],
                    $mulai === null ? 'Tanggal belum diisi.' : null,
                ]));
                $rows[] = [
                    'Tgl_Trs' => $header['tanggal'] ?? null,
                    'ShiftCode' => (string) $shift,
                    'Time_Start' => $mulai,
                    'Time_End' => $selesai,
                    'Time_Total' => $e['duration_minutes'],
                    'MesinCode' => $header['mesin'] ?? null,
                    'NIK' => $header['nik'] ?? null,
                    'Speed_Mesin' => $header['speed'] ?? null,
                    'ProblemCode' => $r['status'] === 'resolved' ? $r['problem_code'] : null,
                    'Problem_Desc' => $r['status'] === 'resolved' ? $r['problem_desc'] : null,
                    'ITEMNO' => $header['itemno'] ?? null,
                    '_raw_code' => $e['raw_code'],
                    '_alasan' => $alasan,
                ];
            }
        }

        return $rows;
    }

    /**
     * Bandingkan kode AI dengan kode akhir petugas (untuk Statistik AI).
     *
     * @return array{berisi: int, ragu: int, dikoreksi: int, dikoreksi_ragu: int, dikoreksi_yakin: int, daftar: array}
     */
    public function koreksi(array $tinjauan, array $grid): array
    {
        $n = ['berisi' => 0, 'ragu' => 0, 'dikoreksi' => 0, 'dikoreksi_ragu' => 0, 'dikoreksi_yakin' => 0, 'daftar' => []];
        foreach ($tinjauan['baris'] as $shift => $row) {
            if (! isset($grid[$shift])) {
                continue; // baris shift diabaikan petugas — bukan koreksi atas bacaan AI
            }
            foreach ($row['blok'] as $b => $blok) {
                foreach ($blok['kotak'] as $k => $kotak) {
                    $akhir = $grid[$shift][$b][$k] ?? null;
                    $akhir = $akhir === null || $akhir === '' ? null : strtolower(trim($akhir));
                    $n['berisi'] += $kotak['kode'] !== null || $akhir !== null ? 1 : 0;
                    $n['ragu'] += $kotak['ragu'] ? 1 : 0;
                    if ($akhir !== $kotak['kode']) {
                        $n['dikoreksi']++;
                        $n[$kotak['ragu'] ? 'dikoreksi_ragu' : 'dikoreksi_yakin']++;
                        $n['daftar'][] = ['shift' => (string) $shift, 'blok' => $b, 'kotak' => $k + 1, 'ai' => $kotak['kode'], 'akhir' => $akhir, 'ragu' => $kotak['ragu']];
                    }
                }
            }
        }

        return $n;
    }

    /** Teks tanggal kertas ("Sabtu, 13-06-2026") → Y-m-d, atau null. */
    public static function tanggalIso(?string $raw, ?string $iso = null): ?string
    {
        if ($iso && preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
            return $iso;
        }
        if ($raw && preg_match('/(\d{1,2})[\-\/.](\d{1,2})[\-\/.](\d{4})/', $raw, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return null;
    }

    /** "AN1" → kode mesin yang cocok di daftar (huruf sama + angka sama), atau null. */
    public static function cocokkanMesin(?string $raw, array $daftarKode): ?string
    {
        if (! $raw || ! preg_match('/^\s*([A-Za-z]+)\s*-?\s*0*(\d+)\s*$/', $raw, $m)) {
            return null;
        }
        foreach ($daftarKode as $kode) {
            if (preg_match('/^([A-Za-z]+)0*(\d+)$/', $kode, $k) && strcasecmp($k[1], $m[1]) === 0 && (int) $k[2] === (int) $m[2]) {
                return $kode;
            }
        }

        return null;
    }

    protected function waktu(?string $tanggal, array $e): array
    {
        if (! $tanggal) {
            return [null, null];
        }
        $mulai = Carbon::parse($tanggal)->addDays($e['start_day_offset'])->setTime($e['start_hour'], $e['start_minute']);
        $selesai = Carbon::parse($tanggal)->addDays($e['end_day_offset'])->setTime($e['end_hour'], $e['end_minute']);

        return [$mulai->format('Y-m-d H:i:s'), $selesai->format('Y-m-d H:i:s')];
    }
}
