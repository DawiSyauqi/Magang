<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmMesinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'raw_text' => ['required', 'string', 'max:255'],
            'resrceno_terpilih' => ['required', 'string', 'max:50'],
        ];
    }
}