<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'icon' => $this->icon, 'tone' => $this->tone, 'title' => $this->title,
            'body' => $this->body, 'at' => $this->created_at?->toIso8601String(), 'read' => (bool) $this->read_at, 'to' => $this->to,
        ];
    }
}
