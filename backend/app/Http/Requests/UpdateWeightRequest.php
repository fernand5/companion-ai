<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWeightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'weight_kg' => ['required', 'numeric', 'min:20', 'max:400'],
            'logged_date' => ['nullable', 'date'],
        ];
    }
}
