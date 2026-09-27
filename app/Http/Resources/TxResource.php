<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** شکل `Tx` در mock.ts */
class TxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind, 'amount' => (int) $this->amount,
            'at' => $this->created_at?->toIso8601String(), 'title' => $this->title,
            'code' => $this->order?->code, 'meta' => $this->meta,
            'balanceAfter' => (int) $this->balance_after, 'heldAfter' => (int) $this->held_after,
        ];
    }
}
