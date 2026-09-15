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
                'sets' => $e->sets,
                'reps' => $e->reps,
                'weight_kg' => $e->weight_kg !== null ? (float) $e->weight_kg : null,
                'duration_seconds' => $e->duration_seconds,
                'notes' => $e->notes,
            ])),
        ];
    }
}
