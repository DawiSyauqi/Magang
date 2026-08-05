<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'uuid'],
            'shift' => ['required', 'string', 'in:1,2,3'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'Sesi foto tidak ditemukan, silakan foto ulang.',
            'token.uuid' => 'Sesi foto tidak valid, silakan foto ulang.',
            'shift.in' => 'Shift harus 1, 2, atau 3.',
        ];
    }
}