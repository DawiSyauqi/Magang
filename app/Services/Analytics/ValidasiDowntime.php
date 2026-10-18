<?php

namespace App\Services\Analytics;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Validasi otomatis data downtime (perencanaan.md saran 2.7) — mencegah data
 * salah masuk ke chart. Dipakai dua cara:
 *  - periksa(): memindai data yang SUDAH ada (halaman Pemeriksaan Data);
 *  - periksaBaru(): memeriksa baris yang AKAN disimpan terhadap data yang ada
 *    (nanti dipakai form input & simpan hasil scan di halaman asli).
 *
 * Posisi waktu dihitung sebagai "menit ke-n dalam shift" (0..479), bukan
 * datetime, karena Time_Start shift 3 setelah 00:00 tetap bertanggal Tgl_Trs
 * (aturan 6.2) sehingga datetime-nya bisa lebih kecil dari jam 23:xx.
 *
 * Tiap temuan: [jenis, tingkat ('galat'|'peringatan'), judul, pesan, no_trs[],
 *               mesin, tanggal, shift].
 */
class ValidasiDowntime
{
    public const JENIS = [
        'duplikat' => ['Duplikat', 'galat', 'bi-files', 'Catatan sama persis (mesin, shift, jam, kategori) tercatat lebih dari sekali.'],
        'tumpang_tindih' => ['Jam tumpang tindih', 'galat', 'bi-intersect', 'Dua kejadian di mesin & shift yang sama saling menimpa jamnya.'],
        'melebihi_shift' => ['Melebihi durasi shift', 'galat', 'bi-hourglass-bottom', 'Total downtime satu mesin dalam satu shift lebih dari durasi shift.'],
        'durasi_tidak_cocok' => ['Durasi tidak cocok', 'galat', 'bi-calculator', 'Time_Total berbeda dengan selisih jam mulai–selesai, atau durasinya nol/negatif.'],
        'masa_depan' => ['Tanggal/jam di masa depan', 'galat', 'bi-calendar-x', 'Kejadian bertanggal atau berjam setelah saat ini.'],
        'di_luar_shift' => ['Di luar jam shift', 'peringatan', 'bi-clock', 'Jam kejadian keluar dari rentang jam shift yang dipilih.'],
    ];

    private int $menitShift;

    public function __construct()
    {
        $this->menitShift = (int) config('produksi.menit_per_shift', 480);
    }

    /**
     * Periksa sekumpulan baris yang sudah tersimpan.
     *
     * @return array<int, array>
     */
    public function periksa(Collection $rows, ?Carbon $sekarang = null): array
    {
        $sekarang ??= Carbon::now('Asia/Jakarta');
        $temuan = [];

        foreach ($rows as $r) {
            array_push($temuan, ...$this->periksaBaris($r, $sekarang));
        }

        foreach ($rows->groupBy(fn ($r) => $r->MesinCode.'|'.$r->Tgl_Trs.'|'.$r->ShiftCode) as $g) {
            array_push($temuan, ...$this->periksaKelompok($g->values()));
        }

        return $this->urutkan($temuan);
    }

    /**
     * Periksa baris BARU (belum tersimpan) terhadap data yang sudah ada.
     * Baris baru boleh belum punya No_Trs (dipakai label "baru #n").
     *
     * @param  array<int, object>  $baru
     * @return array<int, array>
     */
    public function periksaBaru(array $baru, Collection $ada, ?Carbon $sekarang = null): array
    {
        $sekarang ??= Carbon::now('Asia/Jakarta');
        $baru = array_values(array_map(function ($r, $i) {
            $r = clone (object) $r;
            $r->No_Trs = ($r->No_Trs ?? null) ?: 'baru #'.($i + 1);
            $r->_baru = true;

            return $r;
        }, $baru, array_keys($baru)));

        $temuan = [];
        foreach ($baru as $r) {
            array_push($temuan, ...$this->periksaBaris($r, $sekarang));
        }

        $semua = $ada->concat($baru);
        foreach ($semua->groupBy(fn ($r) => $r->MesinCode.'|'.$r->Tgl_Trs.'|'.$r->ShiftCode) as $g) {
            if (! $g->contains(fn ($r) => ! empty($r->_baru))) {
                continue; // masalah lama di data yang sudah ada bukan urusan input ini
            }
            foreach ($this->periksaKelompok($g->values()) as $t) {
                if ($t['jenis'] === 'melebihi_shift' || $this->melibatkanBaru($t, $g)) {
                    $temuan[] = $t;
                }
            }
        }

        return $this->urutkan($temuan);
    }

    /**
     * Ringkasan per jenis + daftar No_Trs yang punya temuan (untuk penanda di tabel).
     */
    public function ringkas(array $temuan): array
    {
        $perJenis = [];
        foreach (self::JENIS as $kode => [$judul, $tingkat, $ikon, $jelas]) {
            $perJenis[$kode] = ['kode' => $kode, 'judul' => $judul, 'tingkat' => $tingkat, 'ikon' => $ikon, 'jelas' => $jelas,
                'jumlah' => count(array_filter($temuan, fn ($t) => $t['jenis'] === $kode))];
        }
        $noTrs = [];
        foreach ($temuan as $t) {
            foreach ($t['no_trs'] as $n) {
                $noTrs[$n][] = $t['judul'];
            }
        }

        return [
            'total' => count($temuan),
            'galat' => count(array_filter($temuan, fn ($t) => $t['tingkat'] === 'galat')),
            'peringatan' => count(array_filter($temuan, fn ($t) => $t['tingkat'] === 'peringatan')),
            'per_jenis' => $perJenis,
            'no_trs' => array_map(fn ($j) => array_values(array_unique($j)), $noTrs),
        ];
    }

    // ------------------------------------------------------------------

    /** Aturan yang cukup dilihat dari satu baris. */
    private function periksaBaris(object $r, Carbon $sekarang): array
    {
        $hasil = [];
        [$awal, $akhir] = $this->posisi($r);
        $durasiJam = $this->selisihJam($r);

        if ((int) $r->Time_Total <= 0 || $durasiJam !== (int) $r->Time_Total) {
            $hasil[] = $this->temuan('durasi_tidak_cocok', $r, [$r->No_Trs],
                'Time_Total '.(int) $r->Time_Total.' menit, padahal jam '.$this->jam($r).' = '.$durasiJam.' menit.');
        }

        // Shift 3: Tgl_Trs = tanggal input (6.2), jadi kejadian 23:xx yang diinput pagi
        // hari tercatat "nanti malam" — cukup tanggalnya yang tidak boleh lewat hari ini.
        $mulai = Carbon::parse($r->Time_Start, 'Asia/Jakarta');
        if ($r->Tgl_Trs > $sekarang->toDateString() || ((string) $r->ShiftCode !== '3' && $mulai->gt($sekarang))) {
            $hasil[] = $this->temuan('masa_depan', $r, [$r->No_Trs],
                'Tanggal '.Carbon::parse($r->Tgl_Trs)->translatedFormat('d M Y').' jam '.substr($r->Time_Start, 11, 5).' belum terjadi (sekarang '.$sekarang->translatedFormat('d M Y H:i').').');
        }

        if ($awal >= $this->menitShift || $akhir > $this->menitShift) {
            $hasil[] = $this->temuan('di_luar_shift', $r, [$r->No_Trs],
                'Jam '.$this->jam($r).' di luar shift '.$r->ShiftCode.' ('.$this->jamShift($r->ShiftCode).'). Mungkin shift atau jamnya salah pilih.');
        }

        return $hasil;
    }

    /** Aturan yang membandingkan baris-baris di mesin + tanggal + shift yang sama. */
    private function periksaKelompok(Collection $g): array
    {
        $hasil = [];
        $contoh = $g->first();

        $total = (int) $g->sum('Time_Total');
        if ($total > $this->menitShift) {
            $hasil[] = $this->temuan('melebihi_shift', $contoh, $g->pluck('No_Trs')->all(),
                $g->count().' kejadian berjumlah '.$total.' menit, lebih dari '.$this->menitShift.' menit per shift.');
        }

        $duplikat = [];
        for ($i = 0; $i < $g->count(); $i++) {
            for ($j = $i + 1; $j < $g->count(); $j++) {
                $a = $g[$i];
                $b = $g[$j];
                if ($this->kunciSama($a) === $this->kunciSama($b)) {
                    $duplikat[$a->No_Trs.'|'.$b->No_Trs] = true;
                    $hasil[] = $this->temuan('duplikat', $a, [$a->No_Trs, $b->No_Trs],
                        $a->No_Trs.' dan '.$b->No_Trs.' sama persis: '.$this->jam($a).', '.$a->ProblemCode.' '.$a->Problem_Desc.'. Kemungkinan terinput dua kali.');

                    continue;
                }
                [$a1, $a2] = $this->posisi($a);
                [$b1, $b2] = $this->posisi($b);
                $irisan = min($a2, $b2) - max($a1, $b1);
                if ($irisan > 0) {
                    $hasil[] = $this->temuan('tumpang_tindih', $a, [$a->No_Trs, $b->No_Trs],
                        $a->No_Trs.' ('.$this->jam($a).') dan '.$b->No_Trs.' ('.$this->jam($b).') bertumpuk '.$irisan.' menit.');
                }
            }
        }

        return $hasil;
    }

    private function melibatkanBaru(array $t, Collection $g): bool
    {
        $baru = $g->filter(fn ($r) => ! empty($r->_baru))->pluck('No_Trs')->all();

        return (bool) array_intersect($t['no_trs'], $baru);
    }

    /** [menit awal, menit akhir] relatif terhadap jam mulai shift. */
    private function posisi(object $r): array
    {
        $mulaiShift = $this->menitDariJam(config("produksi.shift.{$r->ShiftCode}.mulai", '00:00'));
        $awal = ($this->menitDariJam(substr($r->Time_Start, 11, 5)) - $mulaiShift + 1440) % 1440;

        return [$awal, $awal + max(0, $this->selisihJam($r))];
    }

    /** Selisih jam selesai − jam mulai (menit), melewati tengah malam bila perlu. */
    private function selisihJam(object $r): int
    {
        $d = $this->menitDariJam(substr($r->Time_End, 11, 5)) - $this->menitDariJam(substr($r->Time_Start, 11, 5));

        return $d < 0 ? $d + 1440 : $d;
    }

    private function menitDariJam(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm.':0'));

        return $h * 60 + $m;
    }

    private function kunciSama(object $r): string
    {
        return implode('|', [substr($r->Time_Start, 11, 5), substr($r->Time_End, 11, 5), $r->ProblemCode, $r->Problem_Desc]);
    }

    private function jam(object $r): string
    {
        return substr($r->Time_Start, 11, 5).'–'.substr($r->Time_End, 11, 5);
    }

    private function jamShift(string $shift): string
    {
        return config("produksi.shift.$shift.mulai").'–'.config("produksi.shift.$shift.selesai");
    }

    private function temuan(string $jenis, object $r, array $noTrs, string $pesan): array
    {
        [$judul, $tingkat, $ikon] = self::JENIS[$jenis];

        return [
            'jenis' => $jenis, 'tingkat' => $tingkat, 'judul' => $judul, 'ikon' => $ikon, 'pesan' => $pesan,
            'no_trs' => array_values(array_unique($noTrs)),
            'mesin' => $r->MesinCode, 'nama_mesin' => $r->MesinName ?? $r->MesinCode,
            'tanggal' => $r->Tgl_Trs, 'shift' => (string) $r->ShiftCode,
        ];
    }

    private function urutkan(array $temuan): array
    {
        $urutan = array_flip(array_keys(self::JENIS));
        usort($temuan, fn ($a, $b) => [$b['tanggal'], $urutan[$a['jenis']], $a['mesin'], $a['shift']] <=> [$a['tanggal'], $urutan[$b['jenis']], $b['mesin'], $b['shift']]);

        return $temuan;
    }
}
