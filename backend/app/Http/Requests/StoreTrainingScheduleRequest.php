<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTrainingScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'activity_type' => ['required', 'string', 'max:255'],
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['required', 'date_format:H:i'],
            'expected_duration_minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'intensity' => ['nullable', 'string', 'in:low,moderate,high'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
