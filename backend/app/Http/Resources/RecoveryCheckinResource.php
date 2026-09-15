<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecoveryCheckinResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'checkin_date' => $this->checkin_date->toDateString(),
            'energy' => $this->energy,
            'soreness' => $this->soreness,
            'motivation' => $this->motivation,
            'perceived_difficulty' => $this->perceived_difficulty,
            'pain_notes' => $this->pain_notes,
        ];
    }
}
