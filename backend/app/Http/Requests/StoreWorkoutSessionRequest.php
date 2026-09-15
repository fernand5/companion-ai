<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkoutSessionRequest extends FormRequest
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
            'notes' => ['nullable', 'string', 'max:2000'],
            'exercises' => ['nullable', 'array'],
            'exercises.*.exercise_name' => ['required_with:exercises', 'string', 'max:255'],
            'exercises.*.sets' => ['nullable', 'integer', 'min:0', 'max:50'],
            'exercises.*.reps' => ['nullable', 'integer', 'min:0', 'max:200'],
            'exercises.*.weight_kg' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'exercises.*.duration_seconds' => ['nullable', 'integer', 'min:0', 'max:7200'],
            'exercises.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
