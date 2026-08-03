<?php

namespace App\Http\Requests;

use App\Models\OperatorMaster;
use App\Models\ProblemMaster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePerformaMesinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // otorisasi menu sudah ditangani middleware 'menu.access'
    }

    public function rules(): array
    {
        return [
            'tgl_trs' => ['required', 'date'],
            'shift' => ['required', Rule::in(['1', '2', '3'])],

            // AMAN: MFRESMAS & ICITEM ada di database DEFAULT (nama tabel
            // tanpa titik), jadi Rule::exists() masih boleh dipakai di sini.
            'mesin_code' => ['required', Rule::exists('MFRESMAS', 'RESRCENO')],
            'itemno' => ['required', Rule::exists('ICITEM', 'FMTITEMNO')],

            // TIDAK AMAN kalau pakai Rule::exists(): OperatorMaster & ProblemMaster
            // tabelnya di database LAIN, ditulis format 'db.dbo.Tabel' (ada titik).
            // Rule::exists()/Rule::unique() membaca bagian sebelum titik pertama
            // sebagai NAMA KONEKSI (bukan nama database SQL Server), jadi akan
            // selalu gagal dengan error "Database connection [...] not configured".
            // Solusi: validasi manual lewat closure, pakai Eloquent Model biasa
            // (yang menangani format 3-bagian dengan benar sbg SQL Server, BUKAN
            // lewat mekanisme koneksi Laravel).
            'nik' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (! OperatorMaster::where('NIK', $value)->exists()) {
                        $fail('NIK Operator tidak ditemukan.');
                    }
                },
            ],
            'speed_mesin' => ['required', 'numeric', 'min:0'],
            'time_start' => ['required', 'date_format:H:i'],
            'time_end' => ['required', 'date_format:H:i'],
            'problem_code' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (! ProblemMaster::where('ProblemCode', $value)->exists()) {
                        $fail('Kode Masalah yang dipilih tidak valid.');
                    }
                },
            ],
            'problem_desc' => ['required', 'string', 'max:50'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tgl_trs' => 'Tanggal Transaksi',
            'shift' => 'Shift',
            'mesin_code' => 'Mesin',
            'nik' => 'Operator (NIK)',
            'speed_mesin' => 'Kecepatan Mesin',
            'time_start' => 'Waktu Mulai',
            'time_end' => 'Waktu Selesai',
            'problem_code' => 'Kode Masalah (Kategori)',
            'problem_desc' => 'Deskripsi Masalah',
            'itemno' => 'Nomor Item / Produk',
        ];
    }

    public function messages(): array
    {
        return [
            'mesin_code.exists' => 'Mesin yang dipilih tidak valid.',
            'itemno.exists' => 'Nomor Item yang dipilih tidak valid.',
        ];
    }
}