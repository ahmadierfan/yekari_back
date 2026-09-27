<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** شکل `MissionType` + `Tariff` در domain.ts */
class MissionTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key, 'title' => $this->title, 'hint' => $this->hint, 'icon' => $this->icon,
            'fields' => $this->fields, 'needsDropoff' => $this->needs_dropoff, 'estimatedMinutes' => $this->estimated_minutes,
            'baseFee' => $this->base_fee, 'base' => $this->base_fee, 'perKm' => $this->per_km, 'perMin' => $this->per_min,
            'active' => $this->active,
        ];
    }
}
