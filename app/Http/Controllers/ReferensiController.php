<?php

namespace App\Http\Controllers;

use App\Models\ItemLookup;
use App\Models\MesinMaster;
use App\Models\OperatorMaster;
use App\Models\ProblemDetailMaster;
use App\Models\ProblemMaster;
use Illuminate\Http\Request;

/**
 * Endpoint JSON untuk data referensi/dropdown pada Modal Tambah/Edit (FR-08, FR-08a, FR-08b).
 * Semua route ini ada di dalam grup middleware ['auth','menu.access'] — lihat routes/web.php.
 */
class ReferensiController extends Controller
{
    /**
     * Daftar Mesin (MFRESMAS) — dipakai untuk dropdown "Mesin (Kode)".
     *
     * FIX: RESRCENO & DESC kemungkinan bertipe CHAR (fixed-length) di
     * database, sehingga hasil SELECT mentah selalu dipadding spasi di
     * belakang. Dibungkus RTRIM() supaya nilainya bersih & konsisten
     * dengan nilai yang tersimpan di tabel transaksi (MFDOWNTIME).
     */
    public function mesin()
    {
        $data = MesinMaster::selectRaw('RTRIM(RESRCENO) as RESRCENO, RTRIM([DESC]) as [DESC]')
            ->orderBy('RESRCENO')
            ->get();

        return response()->json($data->map(fn ($m) => [
            'kode' => $m->RESRCENO,
            'nama' => $m->DESC,
        ]));
    }

    /**
     * Pencarian Operator (TMMESINOP) by NIK atau nama — dipakai untuk
     * "Operator (NIK)" yang bergaya search, bukan dropdown biasa.
     *
     * FIX: NIK & FullName juga dibungkus RTRIM(), antisipasi tipe CHAR
     * yang sama seperti MFRESMAS (belum dikonfirmasi, tapi aman ditambah).
     */
    public function operator(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $query = OperatorMaster::query()->orderBy('NIK');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('NIK', 'like', "%{$q}%")
                    ->orWhere('FullName', 'like', "%{$q}%");
            });
        }

        $data = $query->limit(20)->get(['NIK', 'FullName']);

        return response()->json($data->map(fn ($o) => [
            'nik' => trim($o->NIK),
            'nama' => trim($o->FullName),
        ]));
    }

    /**
     * Daftar Kode Masalah level KATEGORI (MFPROBLEM) — dropdown "Kode Masalah (Kategori)".
     *
     * FIX: sama, dibungkus RTRIM() untuk antisipasi kolom CHAR.
     */
    public function problemKategori()
    {
        $data = ProblemMaster::selectRaw('RTRIM(ProblemCode) as ProblemCode, RTRIM(ProblemDesc) as ProblemDesc')
            ->orderBy('ProblemCode')
            ->get();

        return response()->json($data->map(fn ($p) => [
            'kode' => $p->ProblemCode,
            'nama' => $p->ProblemDesc,
        ]));
    }

    public function problemDetail(Request $request)
    {
        $kategori = $request->query('kategori');

        if (! $kategori) {
            return response()->json([]);
        }

        $data = ProblemDetailMaster::where('ProblemCode', $kategori)
            ->selectRaw('RTRIM(ProblemCodeD) as ProblemCodeD, RTRIM(ProblemDescD) as ProblemDescD')
            ->orderBy('ProblemCodeD')
            ->get();

        return response()->json($data->map(fn ($d) => [
            'kode' => $d->ProblemCodeD,
            'nama' => $d->ProblemDescD,
        ]));
    }

    /**
     * Daftar Nomor Item, difilter berdasarkan Mesin yang dipilih (WCCD = kode
     * Mesin) — dropdown "Nomor Item / Produk" (cascading, FR-08b).
     *
     * Tidak perlu diubah — ItemLookup::forMesin() sudah pakai RTRIM() dari awal.
     */
    public function item(Request $request)
    {
        $mesinCode = $request->query('mesin');

        if (! $mesinCode) {
            return response()->json([]);
        }

        $data = ItemLookup::forMesin($mesinCode);

        return response()->json(collect($data)->map(fn ($i) => [
            'kode' => $i->ITEMNO,
            'nama' => $i->ITEMDESC,
        ]));
    }
}