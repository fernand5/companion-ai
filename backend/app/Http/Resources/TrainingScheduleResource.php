<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'activity_type' => $this->activity_type,
            'day_of_week' => $this->day_of_week,
            'start_time' => substr((string) $this->start_time, 0, 5),
            'expected_duration_minutes' => $this->expected_duration_minutes,
            'intensity' => $this->intensity,
            'notes' => $this->notes,
        ];
    }
}
