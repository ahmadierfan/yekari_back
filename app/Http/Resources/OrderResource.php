<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * شکل `Order` در mock.ts اپ‌ها — تا استورها بدون تغییر صفحه‌ها سیم‌کشی شوند.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isCourierView = $user && $this->courier_id === $user->id && $this->customer_id !== $user->id;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->mission_type,
            'status' => $this->status,
            'disputedFrom' => $this->disputed_from,
            'createdAt' => $this->created_at?->toIso8601String(),
            'scheduledFor' => $this->scheduled_from ? ['from' => $this->scheduled_from->toIso8601String(), 'to' => $this->scheduled_to?->toIso8601String()] : null,
            'pickup' => $this->pickup,
            'dropoff' => $this->dropoff,
            'description' => $this->description,
            'note' => $this->note,
            'photos' => $this->whenLoaded('attachments', fn () => $this->attachments->where('kind', 'photo')->count(), 0),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id, 'kind' => $a->kind, 'url' => Storage::disk('public')->url($a->path), 'amount' => $a->amount,
            ])->values()),
            'budgetCap' => $this->budget_cap,
            'distanceKm' => $this->distance_km,
            'price' => [
                'service' => (int) $this->price_service, 'distance' => (int) $this->price_distance,
                'waiting' => (int) $this->price_waiting, 'goods' => (int) $this->price_goods, 'discount' => (int) $this->price_discount,
            ],
            'total' => $this->total(),
            'courierPayout' => $this->when($isCourierView || $user?->can('orders.view'), fn () => (int) $this->courier_payout ?: PricingService::courierShare($this->serviceTotal(), $this->commission_rate)),
            'commission' => $this->when($user?->can('orders.view'), (int) $this->commission_amount),
            'courier' => $this->whenLoaded('courier', fn () => $this->courier ? new CourierPublicResource($this->courier) : null),
            'customer' => $this->when($isCourierView || $user?->can('orders.view'), fn () => [
                'id' => $this->customer_id,
                'name' => $this->customer->name,
                // پیک شمارهٔ کامل را نمی‌بیند
                'mobile' => $user?->can('orders.view') ? $this->customer->mobile : substr($this->customer->mobile, 0, 4).'***'.substr($this->customer->mobile, -4),
            ]),
            'escrow' => $this->escrow,
            'payMethod' => $this->pay_method,
            'paymentStatus' => $this->payment_status,
            'eta' => $this->eta_minutes,
            'rating' => $this->rating,
            'tip' => (int) $this->tip ?: null,
            'ratingTags' => $this->rating_tags,
            'ratingNote' => $this->rating_note,
            'costCenter' => $this->whenLoaded('costCenter', fn () => $this->costCenter?->title),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->name),
            'startedAt' => $this->started_at?->toIso8601String(),
            'finishedAt' => $this->finished_at?->toIso8601String(),
            'extraMinutes' => $this->extra_minutes ?: null,
            'cancelReason' => $this->cancel_reason,
            'timeline' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'status' => $e->status, 'at' => $e->created_at?->toIso8601String(), 'note' => $e->note,
            ])),
        ];
    }
}
