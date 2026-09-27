<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** شکل `Address` در mock.ts اپ مشتری */
class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'detail' => $this->detail, 'icon' => $this->icon,
            'x' => $this->map_x, 'y' => $this->map_y, 'lat' => (float) $this->lat, 'lng' => (float) $this->lng,
        ];
    }
}
