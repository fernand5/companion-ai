<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'logged_date' => $this->logged_date->toDateString(),
            'duration_minutes' => $this->duration_minutes,
            'distance_km' => $this->distance_km !== null ? (float) $this->distance_km : null,
            'speed_kmh' => $this->speedKmh(),
            'pace_seconds_per_km' => $this->paceSecondsPerKm(),
            'intensity' => $this->intensity,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'workout_session_id' => $this->workout_session_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
