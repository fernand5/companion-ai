<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTrainingScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'activity_type' => ['sometimes', 'required', 'string', 'max:255'],
            'day_of_week' => ['sometimes', 'required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'expected_duration_minutes' => ['sometimes', 'required', 'integer', 'min:5', 'max:600'],
            'intensity' => ['nullable', 'string', 'in:low,moderate,high'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
