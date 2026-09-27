<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'bank' => $this->bank, 'pan' => $this->pan, 'iban' => $this->iban ?? '', 'owner' => $this->owner, 'primary' => $this->is_primary];
    }
}
