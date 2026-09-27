<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** شکل `ChatMsg` در domain.ts */
class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $url = $this->path ? Storage::disk('public')->url($this->path) : null;

        return [
            'id' => $this->id,
            'from' => $this->sender_role,
            'kind' => $this->kind,
            'text' => $this->text,
            'audio' => $this->kind === 'voice' ? ['url' => $url, 'duration' => (int) $this->duration] : null,
            'image' => $this->kind === 'image' ? ['url' => $url] : null,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
