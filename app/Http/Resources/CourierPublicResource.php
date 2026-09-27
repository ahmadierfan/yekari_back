<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * پیک از دید مشتری — شکل `Courier` در mock.ts. شمارهٔ واقعی داده نمی‌شود (تماس از
 * طریق شمارهٔ واسط؛ تا آن سرویس وصل شود `phone` خالی است و اپ دکمهٔ تماس را پنهان می‌کند).
 *
 * @mixin User
 */
class CourierPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $p = $this->courierProfile;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar' => $this->avatar,
            'rating' => (float) ($p->rating ?? 0),
            'ratingCount' => (int) ($p->rating_count ?? 0),
            'missions' => (int) ($p->missions_count ?? 0),
            'vehicle' => $p->vehicle ?? '',
            'plate' => $p->plate ?? null,
            'phone' => null,
            'online' => (bool) ($p->online ?? false),
            'minPayout' => (int) ($p->min_payout ?? 0),
            'location' => $p && $p->lat ? ['lat' => $p->lat, 'lng' => $p->lng, 'at' => $p->located_at?->toIso8601String()] : null,
        ];
    }
}
