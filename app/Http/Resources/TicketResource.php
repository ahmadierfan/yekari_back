<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** شکل `Ticket` در mock.ts — `from` از دید درخواست‌دهنده: me | agent */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'topic' => $this->topic, 'status' => $this->status,
            'subject' => $this->subject, 'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'orderCode' => $this->order?->code, 'orderId' => $this->order_id,
            'photos' => $this->relationLoaded('messages') ? $this->messages->sum(fn ($m) => count($m->attachments ?? [])) : 0,
            'user' => $this->when($request->user()?->can('tickets.view'), fn () => ['id' => $this->user_id, 'name' => $this->user?->name, 'mobile' => $this->user?->mobile]),
            'assignee' => $this->when($request->user()?->can('tickets.view'), fn () => $this->assignee?->name),
            'msgs' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($m) => [
                'id' => $m->id, 'from' => $m->from, 'text' => $m->text, 'at' => $m->created_at?->toIso8601String(), 'attachments' => $m->attachments,
            ])),
        ];
    }
}
