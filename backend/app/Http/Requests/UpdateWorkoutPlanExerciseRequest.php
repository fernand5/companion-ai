<?php

namespace App\Http\Requests;

use App\Models\WorkoutPlanExercise;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkoutPlanExerciseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', WorkoutPlanExercise::STATUSES)],
            'actual_sets' => ['nullable', 'integer', 'min:0', 'max:50'],
            'actual_reps' => ['nullable', 'integer', 'min:0', 'max:200'],
            'actual_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'actual_duration_seconds' => ['nullable', 'integer', 'min:0', 'max:7200'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
