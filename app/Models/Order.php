<?php

namespace App\Models;

use App\Domain\Domain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pickup' => 'array', 'dropoff' => 'array', 'rating_tags' => 'array', 'escrow' => 'boolean',
            'distance_km' => 'float', 'commission_rate' => 'float',
            'scheduled_from' => 'datetime', 'scheduled_to' => 'datetime', 'started_at' => 'datetime',
            'finished_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(MissionType::class, 'mission_type', 'key');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OrderAttachment::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(OrderOffer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('id');
    }

    public function isLive(): bool
    {
        return Domain::isLive($this->status);
    }

    /** خدمات + مسافت + انتظار — پایهٔ کمیسیون و سهم پیک (کالا در آن نیست) */
    public function serviceTotal(): int
    {
        return (int) ($this->price_service + $this->price_distance + $this->price_waiting);
    }

    /** همان `orderTotal` فرانت */
    public function total(): int
    {
        return (int) ($this->serviceTotal() + $this->price_goods - $this->price_discount);
    }

    public function logEvent(string $status, ?int $actorId = null, ?string $note = null): void
    {
        $this->events()->create(['status' => $status, 'actor_id' => $actorId, 'note' => $note]);
    }
}
