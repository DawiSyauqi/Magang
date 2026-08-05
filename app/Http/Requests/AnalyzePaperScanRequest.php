<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzePaperScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // pengecekan akses sudah di middleware route (menu.access)
    }

    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240'], // maks 10MB
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Foto kertas wajib diupload.',
            'photo.image' => 'File yang diupload harus berupa gambar.',
            'photo.mimes' => 'Format gambar harus JPG atau PNG.',
            'photo.max' => 'Ukuran foto maksimal 10MB.',
        ];
    }
}