<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Contracts\DowntimeSource;
use App\Services\Pengaturan\PengaturanStore;
use Carbon\Carbon;

/**
 * Pusat pemberitahuan in-app (lonceng di topbar), dihitung langsung saat
 * diminta — tanpa tabel notifikasi (K3). Menggabungkan:
 *  - 2.3 pengingat input : shift yang sudah selesai tetapi sebagian mesin belum
 *                          punya catatan (bisa berarti tanpa downtime → hanya pengingat);
 *  - 2.8 ambang & berulang : mesin melewati target per shift (bila target aktif),
 *                          masalah yang sama ≥ N× dalam 7 hari;
 *  - 2.7 validasi        : catatan bermasalah 7 hari terakhir.
 *
 * Tiap item punya id stabil supaya status "sudah dibaca" bisa diingat browser.
 */
class Pemberitahuan
{
    public function __construct(private DowntimeSource $source, private PengaturanStore $pengaturan)
    {
    }

    /**
     * @param  callable(string $route, array $params): string  $url  pembuat URL dari nama rute TANPA awalan (draft./asli)
     * @return array<int, array{id: string, jenis: string, tingkat: string, ikon: string, judul: string, pesan: string, url: string, waktu: string}>
     */
    public function daftar(bool $isAdmin, callable $url, ?Carbon $sekarang = null): array
    {
        $sekarang ??= Carbon::now('Asia/Jakarta');
        $hariIni = $sekarang->copy()->startOfDay();
        $rows7 = $this->source->rows($hariIni->copy()->subDays(6), $hariIni);

        $items = array_merge(
            $this->pengingatInput($isAdmin, $url, $sekarang),
            $this->targetTerlewati($rows7, $url, $sekarang),
            $this->berulang($rows7, $url, $hariIni),
            $this->validasi($rows7, $url, $hariIni, $sekarang),
        );

        usort($items, fn ($a, $b) => strcmp($b['waktu'], $a['waktu']));

        return $items;
    }

    // ------------------------------------------------------------------

    /** 2.3 — tiga shift terakhir yang sudah selesai (+ jeda input). */
    private function pengingatInput(bool $isAdmin, callable $url, Carbon $sekarang): array
    {
        $jeda = (int) config('produksi.pengingat_input_menit', 60);
        $mesin = $this->source->mesin();
        $items = [];

        foreach ($this->shiftSelesai($sekarang, $jeda, 3) as [$tanggal, $shift, $selesai]) {
            // Tgl_Trs shift 3 mengikuti tanggal input (6.2): bisa tanggal mulai shift
            // atau tanggal sesudahnya, jadi keduanya dianggap milik shift ini.
            $sampai = $shift === '3' ? $tanggal->copy()->addDay() : $tanggal;
            $ada = $this->source->rows($tanggal, $sampai, ['shift' => [$shift]])->pluck('MesinCode')->unique();
            $belum = array_diff(array_keys($mesin), $ada->all());
            if (! $belum) {
                continue;
            }
            $label = 'Shift '.$shift.' '.$tanggal->translatedFormat('D, d M').' ('.config("produksi.shift.$shift.mulai").'–'.config("produksi.shift.$shift.selesai").')';
            $daftar = implode(', ', array_slice($belum, 0, 6)).(count($belum) > 6 ? ' +'.(count($belum) - 6) : '');
            $items[] = [
                'id' => 'input|'.$tanggal->toDateString().'|'.$shift,
                'jenis' => 'input', 'tingkat' => $isAdmin ? 'info' : 'peringatan', 'ikon' => 'bi-clipboard-plus',
                'judul' => $label.': '.count($belum).' dari '.count($mesin).' mesin belum ada catatan',
                'pesan' => $daftar.'. '.($isAdmin ? 'Informasi untuk admin — input dilakukan petugas.' : 'Abaikan bila mesin tersebut memang tanpa downtime.'),
                'url' => $url('ringkasan', ['periode' => 'kustom', 'dari' => $tanggal->toDateString(), 'sampai' => $sampai->toDateString()]),
                'waktu' => $selesai->format('Y-m-d H:i'),
            ];
        }

        return $items;
    }

    /** 2.8 — mesin melewati target per shift (hanya bila admin mengaktifkan target). */
    private function targetTerlewati($rows7, callable $url, Carbon $sekarang): array
    {
        if (! $this->pengaturan->target()['aktif']) {
            return [];
        }
        $batasTanggal = $sekarang->copy()->subDay()->toDateString(); // hari ini & kemarin saja
        $lewat = $rows7->filter(fn ($r) => $r->Tgl_Trs >= $batasTanggal)
            ->groupBy(fn ($r) => $r->Tgl_Trs.'|'.$r->ShiftCode)
            ->map(function ($g) {
                return $g->groupBy('MesinCode')->map(fn ($m) => ['kode' => $m->first()->MesinCode, 'menit' => (int) $m->sum('Time_Total'),
                    'target' => $this->pengaturan->targetShift($m->first()->MesinCode)])
                    ->filter(fn ($m) => $m['menit'] > $m['target'])->sortByDesc(fn ($m) => $m['menit'] - $m['target'])->values();
            })->filter(fn ($m) => $m->isNotEmpty());

        $items = [];
        foreach ($lewat as $kunci => $mesin) {
            [$tanggal, $shift] = explode('|', $kunci);
            $tgl = Carbon::parse($tanggal);
            $items[] = [
                'id' => 'target|'.$kunci.'|'.$mesin->pluck('kode')->implode(','),
                'jenis' => 'target', 'tingkat' => 'galat', 'ikon' => 'bi-speedometer',
                'judul' => $mesin->count().' mesin melewati target di shift '.$shift.' '.$tgl->translatedFormat('D, d M'),
                'pesan' => $mesin->take(5)->map(fn ($m) => $m['kode'].' '.$m['menit'].'/'.$m['target'].' mnt')->implode(' · '),
                'url' => $url('ringkasan', ['periode' => 'kustom', 'dari' => $tanggal, 'sampai' => $tanggal, 'shift' => [$shift]]),
                'waktu' => $tgl->copy()->setTimeFromTimeString(config("produksi.shift.$shift.mulai"))->format('Y-m-d H:i'),
            ];
        }

        return $items;
    }

    /** 2.8 — masalah berulang 7 hari terakhir (indikasi perbaikan belum tuntas), digabung jadi 1 item. */
    private function berulang($rows7, callable $url, Carbon $hariIni): array
    {
        $min = 3;
        $hasil = $rows7->groupBy(fn ($r) => $r->MesinCode.'|'.$r->Problem_Desc)
            ->filter(fn ($g) => $g->count() >= $min)
            ->sortByDesc(fn ($g) => $g->count() * 100000 + $g->sum('Time_Total'))
            ->values();
        if ($hasil->isEmpty()) {
            return [];
        }

        return [[
            'id' => 'ulang|'.$hariIni->toDateString().'|'.$hasil->map(fn ($g) => $g->first()->MesinCode.$g->count())->implode(','),
            'jenis' => 'berulang', 'tingkat' => 'peringatan', 'ikon' => 'bi-arrow-repeat',
            'judul' => $hasil->count().' masalah berulang (≥ '.$min.'× dalam 7 hari)',
            'pesan' => $hasil->take(4)->map(fn ($g) => $g->first()->MesinCode.' "'.$g->first()->Problem_Desc.'" '.$g->count().'×')->implode(' · ')
                .($hasil->count() > 4 ? ' · +'.($hasil->count() - 4).' lainnya' : '').'. Perbaikan kemungkinan belum tuntas.',
            'url' => $url('masalah', ['periode' => '7_hari']).'#berulang',
            'waktu' => substr($hasil->map(fn ($g) => $g->max('Time_Start'))->max(), 0, 16),
        ]];
    }

    /** 2.7 — ringkasan hasil pemeriksaan data 7 hari terakhir. */
    private function validasi($rows7, callable $url, Carbon $hariIni, Carbon $sekarang): array
    {
        $validasi = new ValidasiDowntime();
        $r = $validasi->ringkas($validasi->periksa($rows7, $sekarang));
        if (! $r['total']) {
            return [];
        }

        return [[
            'id' => 'validasi|'.$hariIni->toDateString().'|'.$r['total'],
            'jenis' => 'validasi', 'tingkat' => $r['galat'] ? 'galat' : 'peringatan', 'ikon' => 'bi-clipboard-x',
            'judul' => count($r['no_trs']).' catatan 7 hari terakhir perlu diperiksa',
            'pesan' => collect($r['per_jenis'])->where('jumlah', '>', 0)->map(fn ($j) => $j['judul'].' '.$j['jumlah'])->implode(' · '),
            'url' => $url('pemeriksaan', ['periode' => '7_hari']),
            'waktu' => $sekarang->format('Y-m-d H:i'),
        ]];
    }

    /**
     * Shift yang sudah selesai ≥ $jeda menit lalu, terbaru dulu.
     *
     * @return array<int, array{0: Carbon, 1: string, 2: Carbon}> [tanggal mulai shift, shift, waktu selesai]
     */
    private function shiftSelesai(Carbon $sekarang, int $jeda, int $n): array
    {
        $hasil = [];
        for ($d = $sekarang->copy()->startOfDay(); count($hasil) < $n && $d->gte($sekarang->copy()->subDays(3)); $d->subDay()) {
            foreach (['3', '2', '1'] as $s) {
                $mulai = $d->copy()->setTimeFromTimeString(config("produksi.shift.$s.mulai"));
                $selesai = $mulai->copy()->addMinutes((int) config('produksi.menit_per_shift', 480));
                if ($selesai->copy()->addMinutes($jeda)->lte($sekarang)) {
                    $hasil[] = [$d->copy(), $s, $selesai];
                    if (count($hasil) === $n) {
                        break;
                    }
                }
            }
        }

        return $hasil;
    }
}
