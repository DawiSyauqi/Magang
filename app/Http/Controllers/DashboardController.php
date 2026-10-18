<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePerformaMesinRequest;
use App\Services\Analytics\Sources\SqlDowntimeSource;
use App\Services\Analytics\ValidasiDowntime;
use App\Services\Audit\AuditLog;
use App\Models\OperatorMaster;
use App\Models\PerformaMesin;
use App\Models\ProblemDetailMaster;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Simpan / ubah / hapus data downtime (MFDOWNTIME) dari form di halaman Data
 * Downtime. Tabel lama /dashboard sudah digantikan halaman Data Downtime.
 * Setiap perubahan: diperiksa otomatis (saran 2.7), dicatat di Audit Log,
 * dan cache analitik dibuang supaya chart langsung memakai data terbaru.
 */
class DashboardController extends Controller
{
    /**
     * Simpan data baru dari Modal "Tambah Data" (FR-03, FR-04, FR-06, FR-09, FR-10).
     */
    public function store(StorePerformaMesinRequest $request)
    {
        $validated = $request->validated();

        $tglTrs = Carbon::parse($validated['tgl_trs'])->startOfDay();
        [$timeStart, $timeEnd, $totalMenit] = $this->hitungWaktu($tglTrs, $validated['time_start'], $validated['time_end']);

        // No_Trs di-generate berdasarkan TANGGAL TRANSAKSI yang diisi user
        // di form (bukan tanggal sistem hari ini) — lihat PerformaMesin::generateNoTrs().
        if ($tolak = $this->cekOtomatis($request, $validated, $tglTrs, $timeStart, $timeEnd, $totalMenit)) {
            return $tolak;
        }

        $noTrs = PerformaMesin::generateNoTrs($tglTrs);

        PerformaMesin::create([
            'No_Trs' => $noTrs,
            'Tgl_Trs' => $tglTrs,
            'ShiftCode' => $validated['shift'],
            'Time_Start' => $timeStart,
            'Time_End' => $timeEnd,
            'Time_Total' => $totalMenit,
            'MesinCode' => $validated['mesin_code'],
            'MesinLine' => null, // data existing selalu NULL, tidak wajib diisi (PRD Bab 6.1)
            'NIK' => $validated['nik'],
            'Speed_Mesin' => $validated['speed_mesin'],
            'ProblemCode' => $validated['problem_code'],
            'Problem_Desc' => $validated['problem_desc'],
            'ITEMNO' => $validated['itemno'],
            'CrtId' => Auth::user()->UserCode,
            'CrtDate' => now(),
        ]);

        AuditLog::make()->catat('data.tambah', Auth::user()->UserCode, $noTrs, null, $this->ringkas($validated, $timeStart, $timeEnd), $request->ip());
        SqlDowntimeSource::bersihkanCache();

        return redirect()->back()->with('status', "Data {$noTrs} berhasil disimpan.");
    }

    /**
     * Ambil data 1 baris (JSON) untuk mengisi Modal Edit — dipanggil lewat
     * fetch() dari JS saat ikon pensil di Dashboard diklik.
     */
    public function editData(string $noTrs)
    {
        $performaTable = (new PerformaMesin())->getTable();
        $operatorTable = (new OperatorMaster())->getTable();

        $row = DB::table("{$performaTable} as T")
            ->leftJoin('MFRESMAS as M', 'M.RESRCENO', '=', 'T.MesinCode')
            ->leftJoin("{$operatorTable} as O", 'O.NIK', '=', 'T.NIK')
            ->leftJoin('ICITEM as I', 'I.FMTITEMNO', '=', 'T.ITEMNO')
            ->where('T.No_Trs', $noTrs)
            ->select([
                'T.No_Trs', 'T.Tgl_Trs', 'T.ShiftCode', 'T.Time_Start', 'T.Time_End', 'T.Time_Total',
                // FIX: bungkus RTRIM() — MesinCode/NIK/ITEMNO di tabel transaksi
                // sendiri kemungkinan aman (nvarchar), tapi kolom dari tabel
                // master (MesinName, OperatorName) yang di-JOIN WAJIB di-RTRIM
                // karena kolom aslinya (DESC, FullName) ber-tipe CHAR.
                DB::raw('RTRIM(T.MesinCode) as MesinCode'),
                DB::raw('RTRIM(M.[DESC]) as MesinName'),
                DB::raw('RTRIM(T.NIK) as NIK'),
                DB::raw('RTRIM(O.FullName) as OperatorName'),
                'T.Speed_Mesin',
                DB::raw('RTRIM(T.ProblemCode) as ProblemCode'),
                'T.Problem_Desc',
                DB::raw('RTRIM(T.ITEMNO) as ITEMNO'),
                DB::raw('RTRIM(I.[DESC]) as ItemName'),
            ])
            ->first();

        abort_if(! $row, 404);

        // FIX: sebelumnya tidak dicari sama sekali, padahal JS butuh ini untuk
        // otomatis memilih opsi yang benar di dropdown "Detail Masalah".
        // Tabel transaksi cuma menyimpan ProblemCode (kategori) + Problem_Desc
        // (teks deskripsi detail apa adanya) — TIDAK menyimpan ProblemCodeD.
        // Jadi kita cari baris master yang teks deskripsinya PERSIS sama,
        // untuk tahu ProblemCodeD aslinya.
        $problemDetailKode = ProblemDetailMaster::where('ProblemCode', $row->ProblemCode)
            ->where('ProblemDescD', $row->Problem_Desc)
            ->value('ProblemCodeD');

        return response()->json([
            'no_trs' => $row->No_Trs,
            'tgl_trs' => Carbon::parse($row->Tgl_Trs)->format('Y-m-d'),
            'shift' => $row->ShiftCode,
            'mesin_code' => $row->MesinCode,
            'mesin_nama' => $row->MesinName,
            'nik' => $row->NIK,
            'operator_nama' => $row->OperatorName,
            // FIX: key diubah dari 'speed' -> 'speed_mesin', supaya cocok
            // dengan yang dibaca JS (data.speed_mesin) dan nama field form.
            'speed_mesin' => (float) $row->Speed_Mesin,
            'time_start' => $row->Time_Start ? Carbon::parse($row->Time_Start)->format('H:i') : '',
            'time_end' => $row->Time_End ? Carbon::parse($row->Time_End)->format('H:i') : '',
            'total_durasi' => (int) $row->Time_Total,
            'problem_code' => $row->ProblemCode,
            // FIX: field baru, dibutuhkan JS untuk pre-select dropdown Detail Masalah.
            'problem_detail_kode' => $problemDetailKode,
            'problem_desc' => $row->Problem_Desc,
            'itemno' => $row->ITEMNO,
            'item_nama' => $row->ItemName,
        ]);
    }

    /**
     * Simpan perubahan dari Modal "Edit Data" (FR-05, FR-06, FR-10).
     */
    public function update(StorePerformaMesinRequest $request, string $noTrs)
    {
        $performaMesin = PerformaMesin::findOrFail($noTrs);

        $validated = $request->validated();

        $tglTrs = Carbon::parse($validated['tgl_trs'])->startOfDay();
        [$timeStart, $timeEnd, $totalMenit] = $this->hitungWaktu($tglTrs, $validated['time_start'], $validated['time_end']);

        if ($tolak = $this->cekOtomatis($request, $validated, $tglTrs, $timeStart, $timeEnd, $totalMenit, $noTrs)) {
            return $tolak;
        }

        $lama = [
            'tanggal' => optional($performaMesin->Tgl_Trs)->format('Y-m-d'), 'shift' => trim((string) $performaMesin->ShiftCode),
            'mesin' => trim((string) $performaMesin->MesinCode),
            'jam' => optional($performaMesin->Time_Start)->format('H:i').'–'.optional($performaMesin->Time_End)->format('H:i'),
            'kategori' => trim((string) $performaMesin->ProblemCode), 'masalah' => trim((string) $performaMesin->Problem_Desc),
        ];

        $performaMesin->update([
            'Tgl_Trs' => $tglTrs,
            'ShiftCode' => $validated['shift'],
            'Time_Start' => $timeStart,
            'Time_End' => $timeEnd,
            'Time_Total' => $totalMenit,
            'MesinCode' => $validated['mesin_code'],
            'NIK' => $validated['nik'],
            'Speed_Mesin' => $validated['speed_mesin'],
            'ProblemCode' => $validated['problem_code'],
            'Problem_Desc' => $validated['problem_desc'],
            'ITEMNO' => $validated['itemno'],
            'UpdId' => Auth::user()->UserCode,
            'UpdDate' => now(),
        ]);

        AuditLog::make()->catat('data.ubah', Auth::user()->UserCode, $noTrs, $lama, $this->ringkas($validated, $timeStart, $timeEnd), $request->ip());
        SqlDowntimeSource::bersihkanCache();

        return redirect()->back()->with('status', "Data {$noTrs} berhasil diperbarui.");
    }

    /**
     * Hapus data (FR-13). Hard delete sesuai keputusan PRD Bab 10 — tabel
     * MFDOWNTIME existing tidak punya kolom status/flag penanda hapus.
     */
    public function destroy(Request $request, string $noTrs)
    {
        $performa = PerformaMesin::findOrFail($noTrs);
        $lama = [
            'tanggal' => optional($performa->Tgl_Trs)->format('Y-m-d'), 'shift' => trim((string) $performa->ShiftCode),
            'mesin' => trim((string) $performa->MesinCode),
            'jam' => optional($performa->Time_Start)->format('H:i').'–'.optional($performa->Time_End)->format('H:i'),
            'menit' => (int) $performa->Time_Total, 'kategori' => trim((string) $performa->ProblemCode),
            'masalah' => trim((string) $performa->Problem_Desc),
        ];
        $performa->delete();
        AuditLog::make()->catat('data.hapus', Auth::user()->UserCode, $noTrs, $lama, null, $request->ip());
        SqlDowntimeSource::bersihkanCache();

        return redirect()->back()->with('status', "Data {$noTrs} berhasil dihapus.");
    }

    /**
     * Pemeriksaan otomatis (saran 2.7) sebelum simpan: catatan sama persis,
     * jam bertumpuk, atau total shift > 480 menit dengan data yang sudah ada di
     * mesin + tanggal + shift yang sama. Petugas bisa tetap menyimpan setelah
     * mencentang "Saya sudah memeriksa" (abaikan_validasi).
     */
    private function cekOtomatis(Request $request, array $v, Carbon $tglTrs, Carbon $timeStart, Carbon $timeEnd, int $totalMenit, ?string $kecuali = null): ?RedirectResponse
    {
        if ($request->boolean('abaikan_validasi')) {
            return null;
        }

        $baru = (object) [
            'No_Trs' => null, 'Tgl_Trs' => $tglTrs->toDateString(), 'ShiftCode' => (string) $v['shift'],
            'Time_Start' => $timeStart->format('Y-m-d H:i'), 'Time_End' => $timeEnd->format('Y-m-d H:i'), 'Time_Total' => $totalMenit,
            'MesinCode' => $v['mesin_code'], 'MesinName' => $v['mesin_code'],
            'ProblemCode' => $v['problem_code'], 'Problem_Desc' => $v['problem_desc'],
        ];
        $ada = (new SqlDowntimeSource())
            ->rows($tglTrs, $tglTrs, ['mesin' => [$v['mesin_code']], 'shift' => [(string) $v['shift']]])
            ->reject(fn ($r) => $r->No_Trs === $kecuali)->values();

        $temuan = collect((new ValidasiDowntime())->periksaBaru([$baru], $ada))
            ->whereIn('jenis', ['duplikat', 'tumpang_tindih', 'melebihi_shift', 'masa_depan'])->values()->all();
        if (! $temuan) {
            return null;
        }

        return back()->withInput()->with('validasi_temuan', $temuan)
            ->withErrors(['validasi' => count($temuan).' masalah ditemukan pemeriksaan otomatis.']);
    }

    /** Ringkasan isian untuk audit log. */
    private function ringkas(array $v, Carbon $timeStart, Carbon $timeEnd): array
    {
        return [
            'tanggal' => Carbon::parse($v['tgl_trs'])->format('Y-m-d'), 'shift' => (string) $v['shift'], 'mesin' => $v['mesin_code'],
            'jam' => $timeStart->format('H:i').'–'.$timeEnd->format('H:i'), 'kategori' => $v['problem_code'], 'masalah' => $v['problem_desc'],
        ];
    }

    /**
     * Hitung Time_Start/Time_End (datetime lengkap) & Time_Total (menit),
     * termasuk jaga-jaga kalau shift melewati tengah malam. Dipakai bersama
     * oleh store() & update() supaya logikanya konsisten (DRY).
     */
    private function hitungWaktu(Carbon $tglTrs, string $jamMulai, string $jamSelesai): array
    {
        [$startH, $startM] = array_map('intval', explode(':', $jamMulai));
        [$endH, $endM] = array_map('intval', explode(':', $jamSelesai));

        $timeStart = $tglTrs->copy()->setTime($startH, $startM);
        $timeEnd = $tglTrs->copy()->setTime($endH, $endM);

        if ($timeEnd->lessThanOrEqualTo($timeStart)) {
            $timeEnd->addDay();
        }

        $totalMenit = $timeStart->diffInMinutes($timeEnd);

        return [$timeStart, $timeEnd, $totalMenit];
    }
}