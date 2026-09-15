<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFitnessProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'height_cm' => ['nullable', 'numeric', 'min:50', 'max:260'],
            'weight_kg' => ['nullable', 'numeric', 'min:20', 'max:400'],
            'age' => ['nullable', 'integer', 'min:10', 'max:120'],
            'sex' => ['nullable', 'string', 'in:male,female,other'],
            'fitness_level' => ['nullable', 'string', 'in:beginner,intermediate,advanced'],
            'primary_goal' => ['nullable', 'string', 'max:255'],
            'secondary_goal' => ['nullable', 'string', 'max:255'],
            'equipment' => ['nullable', 'array'],
            'equipment.*' => ['string', 'max:100'],
            'preferred_training_days' => ['nullable', 'array'],
            'preferred_training_days.*' => ['integer', 'min:0', 'max:6'],
            'preferred_training_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
        ];
    }
}
