<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FitnessProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'height_cm' => $this->height_cm !== null ? (float) $this->height_cm : null,
            'weight_kg' => $this->weight_kg !== null ? (float) $this->weight_kg : null,
            'age' => $this->age,
            'sex' => $this->sex,
            'fitness_level' => $this->fitness_level,
            'primary_goal' => $this->primary_goal,
            'secondary_goal' => $this->secondary_goal,
            'equipment' => $this->equipment ?? [],
            'preferred_training_days' => $this->preferred_training_days ?? [],
            'preferred_training_duration_minutes' => $this->preferred_training_duration_minutes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
