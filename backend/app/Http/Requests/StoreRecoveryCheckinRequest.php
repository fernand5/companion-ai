<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRecoveryCheckinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'checkin_date' => ['nullable', 'date'],
            'energy' => ['required', 'integer', 'min:1', 'max:5'],
            'soreness' => ['required', 'integer', 'min:1', 'max:5'],
            'motivation' => ['required', 'integer', 'min:1', 'max:5'],
            'perceived_difficulty' => ['nullable', 'integer', 'min:1', 'max:5'],
            'pain_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
