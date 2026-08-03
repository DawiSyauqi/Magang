<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * Bukan Eloquent Model biasa — ini query referensi untuk dropdown "Nomor Item / Produk"
 * (FR-08, FR-08b), hasil join 3 tabel di database DEFAULT (MFBOM, MFBMOP, ICITEM),
 * di-cascade berdasarkan Mesin yang dipilih di form (WCCD = kode Mesin/RESRCENO).
 *
 * Persis meniru query yang sudah dikonfirmasi:
 *   SELECT DISTINCT RTRIM(A.ITEMNO) AS ITEMNO, RTRIM(C.[DESC]) AS ITEMDESC
 *   FROM MFBOM AS A LEFT JOIN MFBMOP AS B
 *     ON A.BOMNO = B.BOMNO AND A.REVNO = B.REVNO AND A.ITEMNO = B.ITEMNO
 *   LEFT JOIN ICITEM AS C ON A.ITEMNO = C.FMTITEMNO
 *   WHERE B.WCCD = :mesinCode
 */
class ItemLookup
{
    /**
     * Ambil daftar Nomor Item untuk sebuah kode Mesin (dipakai saat dropdown
     * Mesin di form Tambah/Edit berubah — cascading, FR-08b).
     */
    public static function forMesin(string $mesinCode)
    {
        return DB::table('MFBOM as A')
            ->leftJoin('MFBMOP as B', function ($join) {
                $join->on('A.BOMNO', '=', 'B.BOMNO')
                    ->on('A.REVNO', '=', 'B.REVNO')
                    ->on('A.ITEMNO', '=', 'B.ITEMNO');
            })
            ->leftJoin('ICITEM as C', 'A.ITEMNO', '=', 'C.FMTITEMNO')
            ->where('B.WCCD', $mesinCode)
            ->selectRaw('DISTINCT RTRIM(A.ITEMNO) AS ITEMNO, RTRIM(C.[DESC]) AS ITEMDESC')
            ->orderBy('ITEMNO')
            ->get();
    }

    /**
     * Ambil nama/deskripsi 1 item spesifik by ITEMNO — dipakai untuk autofill
     * "Nama Item" saat mode Edit (data existing sudah punya ITEMNO tersimpan,
     * tanpa perlu tahu MesinCode-nya dulu).
     */
    public static function findByItemNo(string $itemNo)
    {
        return DB::table('ICITEM')
            ->where('FMTITEMNO', $itemNo)
            ->selectRaw('RTRIM(FMTITEMNO) AS ITEMNO, RTRIM([DESC]) AS ITEMDESC')
            ->first();
    }
}
