<?php

namespace App\Http\Controllers\Courier;

use App\Domain\Domain;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\CourierDocument;
use App\Models\CourierProfile;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    private function profile(Request $r): CourierProfile
    {
        return CourierProfile::firstOrCreate(['user_id' => $r->user()->id]);
    }

    public function show(Request $r)
    {
        $p = $this->profile($r)->load('zone');
        $docs = CourierDocument::where('user_id', $r->user()->id)->get()->keyBy('key');

        return response()->json([
            'user' => new UserResource($r->user()),
            'profile' => [
                'state' => $p->state, 'vehicle' => $p->vehicle, 'plate' => $p->plate, 'zoneId' => $p->zone_id,
                'online' => $p->online, 'rating' => (float) $p->rating, 'ratingCount' => $p->rating_count,
                'missions' => $p->missions_count, 'acceptRate' => $p->acceptRate(), 'cancelRate' => $p->cancelRate(),
                'onTimeRate' => $p->onTimeRate(), 'minPayout' => (int) $p->min_payout,
                'location' => $p->lat ? ['lat' => $p->lat, 'lng' => $p->lng] : null,
            ],
            // همان `Record<string, DocStatus>` استور auth اپ پیک
            'docs' => collect(Domain::COURIER_DOCS)->mapWithKeys(fn ($k) => [$k => $docs[$k]->status ?? 'missing']),
            'docReasons' => $docs->filter(fn ($d) => $d->status === 'rejected')->map->reject_reason,
            'verified' => $p->isVerified(),
        ]);
    }

    public function update(Request $r)
    {
        $d = $r->validate([
            'vehicle' => 'sometimes|string|max:32',
            'plate' => 'sometimes|array', 'plate.two' => 'required_with:plate|string|max:3', 'plate.letter' => 'required_with:plate|string|max:3',
            'plate.three' => 'required_with:plate|string|max:4', 'plate.iran' => 'required_with:plate|string|max:3',
            'zoneId' => 'sometimes|nullable|exists:zones,id',
        ]);
        if (array_key_exists('zoneId', $d)) {
            $d['zone_id'] = $d['zoneId'];
            unset($d['zoneId']);
        }
        $this->profile($r)->update($d);

        return $this->show($r);
    }

    /** بارگذاری مدرک → «در حال بررسی». تأیید/رد در پنل (couriers.review) */
    public function uploadDocument(Request $r)
    {
        $d = $r->validate(['key' => ['required', Rule::in(Domain::COURIER_DOCS)], 'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240']);
        $existing = CourierDocument::where('user_id', $r->user()->id)->where('key', $d['key'])->first();
        if ($existing?->status === 'verified') {
            throw new ApiException('این مدرک قبلاً تأیید شده است');
        }
        CourierDocument::updateOrCreate(
            ['user_id' => $r->user()->id, 'key' => $d['key']],
            ['status' => 'pending', 'path' => $r->file('file')->store("courier-docs/{$r->user()->id}", 'local'), 'reject_reason' => null, 'reviewed_by' => null, 'reviewed_at' => null],
        );

        return $this->show($r);
    }

    public function setOnline(Request $r)
    {
        $d = $r->validate(['online' => 'required|boolean', 'lat' => 'nullable|numeric', 'lng' => 'nullable|numeric']);
        $p = $this->profile($r);
        if ($d['online']) {
            if ($p->state !== 'active' || ! $p->isVerified()) {
                throw new ApiException('تا تأیید مدارک، آنلاین‌شدن ممکن نیست', 403);
            }
        }
        $p->update(array_filter([
            'online' => $d['online'], 'lat' => $d['lat'] ?? null, 'lng' => $d['lng'] ?? null,
            'located_at' => isset($d['lat']) ? now() : null,
        ], fn ($v) => $v !== null));
        if ($d['online']) {
            Notifier::send($r->user()->id, 'courier', 'آنلاین شدی', 'به‌محض رسیدن پیشنهاد باخبرت می‌کنیم.', 'radar', 'ok');
        }

        return response()->json(['online' => $p->online]);
    }

    /** موقعیت زنده — اپ هر ~۱۵ ثانیه وقتی آنلاین است می‌فرستد */
    public function location(Request $r)
    {
        $d = $r->validate(['lat' => 'required|numeric|between:24,40', 'lng' => 'required|numeric|between:44,64']);
        $this->profile($r)->update(['lat' => $d['lat'], 'lng' => $d['lng'], 'located_at' => now()]);

        return response()->noContent();
    }
}
