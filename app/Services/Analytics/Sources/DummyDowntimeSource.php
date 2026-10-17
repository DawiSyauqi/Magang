<?php

namespace App\Services\Analytics\Sources;

use App\Services\Analytics\Contracts\DowntimeSource;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Data downtime CONTOH untuk halaman /draft (tanpa database).
 *
 * - Deterministik: tanggal yang sama selalu menghasilkan data yang sama
 *   (seed = tanggal), jadi chart tidak berubah tiap refresh.
 * - Bentuk baris sama dengan yang nanti dikembalikan SqlDowntimeSource.
 * - Pola dibuat agak realistis supaya chart "bercerita": beberapa mesin
 *   lebih sering bermasalah, awal shift lebih rawan (setting), Minggu lebih
 *   sepi, durasi tergantung kategori, jam dibulatkan per 10 menit (formulir).
 * - Nama mesin, operator, item dan kategori SEMUANYA FIKTIF.
 */
class DummyDowntimeSource implements DowntimeSource
{
    /** kode => [nama, laju kejadian per shift, kategori favorit] */
    private const MESIN = [
        'AN01' => ['Annealing 01', 0.9, 'D06'],
        'AN02' => ['Annealing 02', 0.7, 'D06'],
        'AN03' => ['Annealing 03', 1.4, 'D01'],
        'DD12' => ['Drawing Dies 12', 1.1, 'D04'],
        'DD13' => ['Drawing Dies 13', 0.8, 'D03'],
        'DD14' => ['Drawing Dies 14', 1.0, 'D04'],
        'DD15' => ['Drawing Dies 15', 0.6, 'D02'],
        'DD16' => ['Drawing Dies 16', 2.1, 'D01'],
        'ML01' => ['Multi Line 01', 1.2, 'D03'],
        'ML02' => ['Multi Line 02', 0.9, 'D05'],
        'ML03' => ['Multi Line 03', 0.5, 'D07'],
        'WD01' => ['Wire Drawing 01', 1.6, 'D02'],
        'WD02' => ['Wire Drawing 02', 0.8, 'D04'],
        'WD03' => ['Wire Drawing 03', 0.4, 'D05'],
    ];

    /** kode => [nama, bobot, durasi min (slot 10 mnt), durasi maks, detail[]] */
    private const KATEGORI = [
        'D01' => ['Kerusakan Mekanik', 25, 2, 9, ['Bearing aus', 'Capstan macet', 'V-belt putus', 'Gearbox bocor oli']],
        'D02' => ['Gangguan Listrik', 12, 1, 4, ['Motor trip', 'Sensor error', 'Inverter alarm']],
        'D03' => ['Material', 18, 1, 5, ['Kawat putus', 'Tunggu material', 'Material kusut', 'Ganti coil']],
        'D04' => ['Setting & Ganti Ukuran', 20, 3, 9, ['Ganti dies', 'Ganti ukuran', 'Setting awal shift']],
        'D05' => ['Kualitas', 10, 1, 4, ['Diameter tidak standar', 'Permukaan tergores', 'Cek sampel QC']],
        'D06' => ['Utilitas', 8, 1, 6, ['Air pendingin habis', 'Tekanan angin turun', 'Listrik PLN padam']],
        'D07' => ['Lain-lain', 7, 1, 3, ['Tunggu operator', 'Kebersihan area', 'Briefing']],
    ];

    private const OPERATOR = [
        '10231' => 'Andi Saputra', '10244' => 'Budi Hartono', '10257' => 'Citra Lestari',
        '10263' => 'Dedi Kurniawan', '10278' => 'Eka Pratama', '10281' => 'Fajar Nugroho',
        '10295' => 'Gilang Ramadhan', '10302' => 'Hendra Wijaya', '10316' => 'Indra Maulana',
        '10329' => 'Joko Susilo', '10334' => 'Kiki Amelia', '10347' => 'Lukman Hakim',
    ];

    private const ITEM = [
        'FG-W200' => 'Kawat 2.00 MM', 'FG-W160' => 'Kawat 1.60 MM', 'FG-W120' => 'Kawat 1.20 MM',
        'FG-W090' => 'Kawat 0.90 MM', 'FG-W250' => 'Kawat 2.50 MM', 'FG-W300' => 'Kawat 3.00 MM',
    ];

    private const PETUGAS = ['PRD01', 'PRD02', 'PRD03', 'PRD04'];

    /** @var array<string, array> cache per tanggal */
    private array $cache = [];

    private int $seed = 1;

    public function rows(CarbonInterface $dari, CarbonInterface $sampai, array $filter = []): Collection
    {
        $hariIni = Carbon::today();
        $rows = [];

        for ($d = Carbon::parse($dari)->startOfDay(); $d->lte($sampai) && $d->lte($hariIni); $d->addDay()) {
            array_push($rows, ...$this->untukTanggal($d->toDateString()));
        }

        return collect($rows)
            ->when(! empty($filter['shift']), fn ($c) => $c->whereIn('ShiftCode', $filter['shift']))
            ->when(! empty($filter['mesin']), fn ($c) => $c->whereIn('MesinCode', $filter['mesin']))
            ->when(! empty($filter['kategori']), fn ($c) => $c->whereIn('ProblemCode', $filter['kategori']))
            ->values();
    }

    public function mesin(): array
    {
        return array_map(fn ($m) => $m[0], self::MESIN);
    }

    public function kategori(): array
    {
        return array_map(fn ($k) => $k[0], self::KATEGORI);
    }

    /** NIK => nama, untuk pilihan operator di layar tinjauan scan (draft). */
    public function operatorList(): array
    {
        return self::OPERATOR;
    }

    /** ITEMNO => nama, untuk pilihan produk di layar tinjauan scan (draft). */
    public function itemList(): array
    {
        return self::ITEM;
    }

    /**
     * Pengganti lookup MFPROBLEMD untuk draft: ProblemCode "D03" + ProblemCodeD "02"
     * → teks detail. Detail di luar daftar contoh tetap dianggap ada ("sub-poin b").
     */
    public function problemDesc(string $problemCode, string $problemCodeD): ?string
    {
        if (! isset(self::KATEGORI[$problemCode])) {
            return null;
        }
        $nomor = (int) $problemCodeD;

        return self::KATEGORI[$problemCode][4][$nomor - 1] ?? self::KATEGORI[$problemCode][0].' – sub-poin '.chr(ord('a') + $nomor - 1);
    }

    private function untukTanggal(string $tanggal): array
    {
        if (isset($this->cache[$tanggal])) {
            return $this->cache[$tanggal];
        }

        $this->seed = crc32('downtime-'.$tanggal) ?: 1;
        $tgl = Carbon::parse($tanggal);
        $faktorHari = $tgl->isSunday() ? 0.35 : ($tgl->isSaturday() ? 0.8 : 1.0);
        $jadwal = config('produksi.shift');
        $operatorKode = array_keys(self::OPERATOR);
        $itemKode = array_keys(self::ITEM);

        $rows = [];
        $urut = ($tgl->day - 1) * 150;

        foreach (self::MESIN as $kodeMesin => [$namaMesin, $laju, $favorit]) {
            $item = $itemKode[$this->acak(0, count($itemKode) - 1)];
            $speed = $this->acak(30, 90) * 10;

            foreach ($jadwal as $shift => $jam) {
                $lajuShift = $laju * $faktorHari * ($shift === '3' ? 1.2 : 1.0);
                $jumlah = $this->poisson($lajuShift);
                if ($jumlah === 0) {
                    continue;
                }

                $nik = $operatorKode[$this->acak(0, count($operatorKode) - 1)];
                $terpakai = array_fill(0, 48, false); // 48 slot x 10 menit = 480 menit
                [$jamMulaiShift] = array_map('intval', explode(':', $jam['mulai']));

                for ($i = 0; $i < $jumlah; $i++) {
                    $kat = $this->pilihKategori($favorit);
                    [$namaKat, , $minSlot, $maxSlot, $detail] = self::KATEGORI[$kat];
                    $durasi = $this->acak($minSlot, $maxSlot);

                    // Awal shift lebih rawan (setting / pemanasan mesin).
                    $mulai = $this->acak(0, 99) < 30 ? $this->acak(0, 5) : $this->acak(0, 48 - $durasi);
                    $mulai = min($mulai, 48 - $durasi);

                    if (in_array(true, array_slice($terpakai, $mulai, $durasi), true)) {
                        continue; // bentrok dengan kejadian lain di shift yang sama
                    }
                    for ($s = $mulai; $s < $mulai + $durasi; $s++) {
                        $terpakai[$s] = true;
                    }

                    // Sama seperti DashboardController::hitungWaktu(): Time_Start = Tgl_Trs + jam,
                    // jadi kejadian shift 3 setelah 00:00 tetap bertanggal Tgl_Trs.
                    $menitDariTengahMalam = ($jamMulaiShift * 60 + $mulai * 10) % 1440;
                    $start = $tgl->copy()->addMinutes($menitDariTengahMalam);
                    $end = $start->copy()->addMinutes($durasi * 10);

                    $rows[] = (object) [
                        'No_Trs' => sprintf('PRB%s%04d', $tgl->format('ym'), ++$urut),
                        'Tgl_Trs' => $tanggal,
                        'ShiftCode' => (string) $shift,
                        'Time_Start' => $start->format('Y-m-d H:i'),
                        'Time_End' => $end->format('Y-m-d H:i'),
                        'Time_Total' => $durasi * 10,
                        'MesinCode' => $kodeMesin,
                        'MesinName' => $namaMesin,
                        'NIK' => $nik,
                        'OperatorName' => self::OPERATOR[$nik],
                        'ProblemCode' => $kat,
                        'ProblemName' => $namaKat,
                        'Problem_Desc' => $detail[$this->acak(0, count($detail) - 1)],
                        'ITEMNO' => $item,
                        'ItemDesc' => self::ITEM[$item],
                        'Speed_Mesin' => $speed,
                        'CrtId' => self::PETUGAS[$this->acak(0, count(self::PETUGAS) - 1)],
                    ];
                }
            }
        }

        // Field tambahan untuk halaman Data Downtime. Diturunkan dari hash No_Trs
        // (bukan PRNG) supaya angka-angka yang sudah ada di Ringkasan tidak bergeser.
        // Jam lokal pabrik (WIB). Catatan baru "ada" setelah petugas menginputnya,
        // jadi kejadian yang waktu input-nya masih di masa depan belum dimunculkan.
        $sekarang = Carbon::now('Asia/Jakarta')->format('Y-m-d H:i');

        foreach ($rows as $r) {
            $h = crc32($r->No_Trs);
            $r->Sumber = $h % 10 < 4 ? 'ai' : 'manual';          // ±40% dari AI Baca Kertas
            $r->CrtDate = Carbon::parse($r->Time_End)->addMinutes(20 + $h % 600)->format('Y-m-d H:i');
            $ubah = $h % 13 === 0 ? Carbon::parse($r->CrtDate)->addMinutes(30 + $h % 300)->format('Y-m-d H:i') : null;
            $r->UpdId = $ubah && $ubah <= $sekarang ? self::PETUGAS[$h % count(self::PETUGAS)] : null;
            $r->UpdDate = $r->UpdId ? $ubah : null;
        }
        $rows = array_values(array_filter($rows, fn ($r) => $r->CrtDate <= $sekarang));

        usort($rows, fn ($a, $b) => strcmp($a->Time_Start, $b->Time_Start));

        return $this->cache[$tanggal] = $rows;
    }

    private function pilihKategori(string $favorit): string
    {
        $bobot = [];
        foreach (self::KATEGORI as $kode => $k) {
            $bobot[$kode] = $k[1] + ($kode === $favorit ? 30 : 0);
        }
        $r = $this->acak(1, array_sum($bobot));
        foreach ($bobot as $kode => $b) {
            if (($r -= $b) <= 0) {
                return $kode;
            }
        }

        return 'D07';
    }

    private function poisson(float $lambda): int
    {
        $l = exp(-$lambda);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= $this->acak(0, 999999) / 1000000;
        } while ($p > $l && $k < 10);

        return $k - 1;
    }

    /** PRNG sederhana ber-seed (LCG) supaya tidak mengganggu mt_rand global. */
    private function acak(int $min, int $max): int
    {
        $this->seed = ($this->seed * 1103515245 + 12345) & 0x7fffffff;

        return $min + ($this->seed % ($max - $min + 1));
    }
}
