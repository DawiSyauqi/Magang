<?php

namespace App\Http\Controllers\Panel;

use App\Http\Requests\StorePerformaMesinRequest;
use App\Models\PerformaMesin;
use App\Services\Analytics\Sources\SqlDowntimeSource;
use App\Services\Analytics\ValidasiDowntime;
use App\Services\Audit\AuditLog;
use App\Services\Scan\ScanStore;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Tambah Data dengan AI Baca Kertas (E6) di APLIKASI ASLI: master dari
 * database, hasil tinjauan disimpan ke MFDOWNTIME.
 *
 * Simpan = aturan yang SAMA dengan input manual (StorePerformaMesinRequest per
 * baris) + pemeriksaan otomatis 2.7 terhadap data yang sudah ada, lalu semua
 * baris ditulis dalam SATU transaksi (semua berhasil atau semua batal) dengan
 * No_Trs dari PerformaMesin::generateNoTrs. No_Trs dicatat di riwayat scan
 * supaya kolom Sumber di Data Downtime menampilkan "AI".
 */
class AppScanController extends ScanController
{
    /** Lewat container supaya uji tampilan tanpa database bisa menggantinya dengan data contoh. */
    private function sumber()
    {
        return app(SqlDowntimeSource::class);
    }

    protected function isAdmin(Request $request): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    protected function petugas(Request $request): string
    {
        return trim((string) Auth::user()?->UserCode);
    }

    protected function rp(): string
    {
        return '';
    }

    protected function ruteFoto(): string
    {
        return 'tambah.foto';
    }

    protected function pencocok(): \App\Services\Scan\PencocokHeader
    {
        return new \App\Services\Scan\PencocokHeader(app(\App\Services\PaperExtraction\MesinAliasStore::class));
    }

    protected function opsiMesin(): array
    {
        return $this->sumber()->mesin();
    }

    protected function opsiOperator(): array
    {
        return $this->sumber()->operatorList();
    }

    protected function opsiItem(?string $mesin): array
    {
        return $this->sumber()->itemList($mesin);
    }

    protected function problemDesc(string $problemCode, string $problemCodeD): ?string
    {
        return $this->sumber()->problemDesc($problemCode, $problemCodeD);
    }

    protected function kategori(): array
    {
        return $this->sumber()->kategori();
    }

    protected function simpanBaris(Request $request, string $id, array $header, array $rows): RedirectResponse|array
    {
        // 1. Aturan input manual, per baris.
        foreach ($rows as $i => $r) {
            $fr = new StorePerformaMesinRequest();
            $fr->merge([
                'tgl_trs' => $header['tanggal'], 'shift' => $r['ShiftCode'], 'mesin_code' => $header['mesin'],
                'nik' => $header['nik'], 'speed_mesin' => $header['speed'],
                'time_start' => substr($r['Time_Start'], 11, 5), 'time_end' => substr($r['Time_End'], 11, 5),
                'problem_code' => $r['ProblemCode'], 'problem_desc' => $r['Problem_Desc'], 'itemno' => $header['itemno'],
            ]);
            $v = Validator::make($fr->all(), $fr->rules(), $fr->messages(), $fr->attributes());
            if ($v->fails()) {
                return back()->withInput()->withErrors(['grid' => 'Kejadian '.($i + 1).' (shift '.$r['ShiftCode'].', '.substr($r['Time_Start'], 11, 5).'–'.substr($r['Time_End'], 11, 5).'): '.$v->errors()->first()]);
            }
        }

        // 2. Pemeriksaan otomatis terhadap data yang sudah ada (bisa "tetap simpan").
        if (! $request->boolean('abaikan_validasi')) {
            $tgl = Carbon::parse($header['tanggal']);
            $nama = $this->opsiMesin()[$header['mesin']] ?? $header['mesin'];
            $baru = array_map(fn ($r) => (object) [
                'No_Trs' => null, 'Tgl_Trs' => $header['tanggal'], 'ShiftCode' => (string) $r['ShiftCode'],
                'Time_Start' => substr($r['Time_Start'], 0, 16), 'Time_End' => substr($r['Time_End'], 0, 16), 'Time_Total' => (int) $r['Time_Total'],
                'MesinCode' => $header['mesin'], 'MesinName' => $nama, 'ProblemCode' => $r['ProblemCode'], 'Problem_Desc' => $r['Problem_Desc'],
            ], $rows);
            $ada = $this->sumber()->rows($tgl, $tgl, ['mesin' => [$header['mesin']]]);
            $temuan = collect((new ValidasiDowntime())->periksaBaru($baru, $ada))
                ->whereIn('jenis', ['duplikat', 'tumpang_tindih', 'melebihi_shift', 'masa_depan'])->values()->all();
            if ($temuan) {
                return back()->withInput()->with('validasi_temuan', $temuan)
                    ->withErrors(['validasi' => count($temuan).' masalah ditemukan pemeriksaan otomatis (mungkin lembar ini sudah pernah diinput).']);
            }
        }

        // 3. Simpan semua baris dalam satu transaksi.
        $kode = $this->petugas($request);
        $noTrs = DB::transaction(function () use ($rows, $header, $kode) {
            $daftar = [];
            foreach ($rows as $r) {
                $no = PerformaMesin::generateNoTrs($header['tanggal']);
                PerformaMesin::create([
                    'No_Trs' => $no,
                    'Tgl_Trs' => Carbon::parse($header['tanggal'])->startOfDay(),
                    'ShiftCode' => (string) $r['ShiftCode'],
                    // Time_Start/End dari GridTimeMerger (sudah benar melewati tengah malam).
                    'Time_Start' => Carbon::parse($r['Time_Start']),
                    'Time_End' => Carbon::parse($r['Time_End']),
                    'Time_Total' => (int) $r['Time_Total'],
                    'MesinCode' => $header['mesin'],
                    'MesinLine' => null,
                    'NIK' => $header['nik'],
                    'Speed_Mesin' => $header['speed'],
                    'ProblemCode' => $r['ProblemCode'],
                    'Problem_Desc' => $r['Problem_Desc'],
                    'ITEMNO' => $header['itemno'],
                    'CrtId' => $kode,
                    'CrtDate' => now(),
                ]);
                $daftar[] = $no;
            }

            return $daftar;
        });

        ScanStore::make()->catatNoTrsAi($noTrs);
        SqlDowntimeSource::bersihkanCache();
        AuditLog::make()->catat('scan.simpan', $kode, $id, null, [
            'mesin' => $header['mesin'], 'tanggal' => $header['tanggal'], 'baris' => count($rows), 'no_trs' => $noTrs,
        ], $request->ip());

        return [count($rows).' kejadian downtime disimpan ke database ('.implode(', ', $noTrs).').', $noTrs];
    }
}
