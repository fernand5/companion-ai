<?php

namespace App\Http\Requests;

use App\Models\WorkoutPlan;
use App\Services\Fitness\ActivityService;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkoutPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'planned_date' => ['nullable', 'date'],
            'activity_type' => ['required', 'string', 'in:'.implode(',', array_diff(ActivityService::TYPES, ['weight']))],
            'title' => ['required', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'in:'.implode(',', WorkoutPlan::SOURCES)],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'reasoning' => ['nullable', 'string', 'max:4000'],
            'reasoning_factors' => ['nullable', 'array', 'max:6'],
            'reasoning_factors.*' => ['string', 'max:255'],
            'training_schedule_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'exercises' => ['nullable', 'array'],
            'exercises.*.exercise_name' => ['required_with:exercises', 'string', 'max:255'],
            'exercises.*.planned_sets' => ['nullable', 'integer', 'min:0', 'max:50'],
            'exercises.*.planned_reps' => ['nullable', 'integer', 'min:0', 'max:200'],
            'exercises.*.planned_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'exercises.*.planned_duration_seconds' => ['nullable', 'integer', 'min:0', 'max:7200'],
        ];
    }
}
