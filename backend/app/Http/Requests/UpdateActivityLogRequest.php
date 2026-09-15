<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActivityLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logged_date' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'intensity' => ['nullable', 'string', 'in:low,moderate,high'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
