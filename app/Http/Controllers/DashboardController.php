<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePerformaMesinRequest;
use App\Models\OperatorMaster;
use App\Models\PerformaMesin;
use App\Models\ProblemDetailMaster;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Tampilkan Dashboard: tabel/card riwayat data performa mesin,
     * difilter per hari (default) atau per bulan, milik petugas yang login.
     *
     * FR-02, FR-07.
     */
    public function index(Request $request)
    {
        $tanggal = $request->input('tanggal', now()->format('Y-m-d'));
        $satuBulan = $request->boolean('satu_bulan');

        $tanggalCarbon = Carbon::parse($tanggal);

        // Ambil nama tabel transaksi langsung dari Model (termasuk prefix
        // database-nya) — supaya tidak perlu hardcode ulang nama tabel di sini.
        $performaTable = (new PerformaMesin())->getTable();

        $query = DB::table("{$performaTable} as T")
            ->leftJoin('MFRESMAS as M', 'M.RESRCENO', '=', 'T.MesinCode')
            ->select([
                'T.No_Trs',
                'T.Tgl_Trs',
                'T.ShiftCode',
                'T.Time_Start',
                'T.Time_End',
                'T.Time_Total',
                'T.MesinCode',
                DB::raw('RTRIM(M.[DESC]) as MesinName'), // FIX: MFRESMAS.DESC ber-tipe CHAR (padding spasi)
            ]);

        // Keputusan: Dashboard menampilkan SEMUA data performa mesin,
        // tidak dibatasi hanya milik petugas yang sedang login.
        // (Sebelumnya sempat difilter via CrtId = UserCode, tapi itu
        // dihapus sesuai keputusan ini.)

        if ($satuBulan) {
            $query->whereYear('T.Tgl_Trs', $tanggalCarbon->year)
                ->whereMonth('T.Tgl_Trs', $tanggalCarbon->month);
        } else {
            $query->whereDate('T.Tgl_Trs', $tanggalCarbon->toDateString());
        }

        $query->orderByDesc('T.Tgl_Trs')->orderByDesc('T.Time_Start');

        $rows = $query->paginate(10)->withQueryString();

        // Format tiap baris jadi bentuk siap-tampil (dipakai sama-sama oleh
        // tabel Desktop maupun card list Mobile di view).
        $rows->getCollection()->transform(function ($row) {
            return (object) [
                'no_trs' => $row->No_Trs,
                'tgl' => Carbon::parse($row->Tgl_Trs)->format('d M Y'),
                'shift' => $row->ShiftCode,
                'start' => $row->Time_Start ? Carbon::parse($row->Time_Start)->format('H:i') : '-',
                'end' => $row->Time_End ? Carbon::parse($row->Time_End)->format('H:i') : '-',
                'total' => (int) $row->Time_Total,
                'mesin' => $row->MesinCode,
                'nama' => $row->MesinName ?? '-',
            ];
        });

        return view('dashboard.index', [
            'rows' => $rows,
            'filterTanggal' => $tanggalCarbon->format('Y-m-d'),
            'filterSatuBulan' => $satuBulan,
        ]);
    }

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

        return redirect()->back()->with('status', "Data {$noTrs} berhasil diperbarui.");
    }

    /**
     * Hapus data (FR-13). Hard delete sesuai keputusan PRD Bab 10 — tabel
     * MFDOWNTIME existing tidak punya kolom status/flag penanda hapus.
     */
    public function destroy(string $noTrs)
    {
        $performa = PerformaMesin::findOrFail($noTrs);
        $performa->delete();

        return redirect()->back()->with('status', "Data {$noTrs} berhasil dihapus.");
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