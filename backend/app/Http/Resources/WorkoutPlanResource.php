<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkoutPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'planned_date' => $this->planned_date->toDateString(),
            'activity_type' => $this->activity_type,
            'title' => $this->title,
            'source' => $this->source,
            'status' => $this->status,
            'duration_minutes' => $this->duration_minutes,
            'reasoning' => $this->reasoning,
            'reasoning_factors' => $this->reasoning_factors ?? [],
            'training_schedule_id' => $this->training_schedule_id,
            'notes' => $this->notes,
            'exercises' => WorkoutPlanExerciseResource::collection($this->whenLoaded('exercises')),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
