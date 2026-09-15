<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkoutPlanExerciseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exercise_name' => $this->exercise_name,
            'planned_sets' => $this->planned_sets,
            'planned_reps' => $this->planned_reps,
            'planned_weight_kg' => $this->planned_weight_kg !== null ? (float) $this->planned_weight_kg : null,
            'planned_duration_seconds' => $this->planned_duration_seconds,
            'position' => $this->position,
            'status' => $this->status,
            'actual_sets' => $this->actual_sets,
            'actual_reps' => $this->actual_reps,
            'actual_weight_kg' => $this->actual_weight_kg !== null ? (float) $this->actual_weight_kg : null,
            'actual_duration_seconds' => $this->actual_duration_seconds,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
