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
            'intensity' => $this->intensity,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'workout_session_id' => $this->workout_session_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
