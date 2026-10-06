<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkoutSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'logged_date' => $this->logged_date->toDateString(),
            'duration_minutes' => $this->duration_minutes,
            'notes' => $this->notes,
            'exercises' => $this->whenLoaded('exercises', fn () => $this->exercises->map(fn ($e) => [
                'id' => $e->id,
                'exercise_name' => $e->exercise_name,
                'exercise_slug' => $e->exercise_slug,
                'recorded_as' => $e->recorded_as ?? 'migrated',
                'planned' => $e->planned_sets !== null || $e->planned_reps !== null || $e->planned_weight_kg !== null || $e->planned_duration_seconds !== null ? [
                    'sets' => $e->planned_sets,
                    'reps' => $e->planned_reps,
                    'weight_kg' => $e->planned_weight_kg !== null ? (float) $e->planned_weight_kg : null,
                    'duration_seconds' => $e->planned_duration_seconds,
                ] : null,
                'performed_sets' => $e->relationLoaded('performedSets') ? $e->performedSets->map(fn ($s) => [
                    'set_number' => $s->set_number,
                    'reps' => $s->reps,
                    'weight_kg' => $s->weight_kg !== null ? (float) $s->weight_kg : null,
                    'duration_seconds' => $s->duration_seconds,
                    'completed' => $s->completed,
                ])->values() : [],
                'sets' => $e->sets,
                'reps' => $e->reps,
                'weight_kg' => $e->weight_kg !== null ? (float) $e->weight_kg : null,
                'duration_seconds' => $e->duration_seconds,
                'notes' => $e->notes,
            ])),
        ];
    }
}
