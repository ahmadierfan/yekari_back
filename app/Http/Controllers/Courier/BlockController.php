<?php

namespace App\Http\Controllers\Courier;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\WorkBlock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** رزرو بلوک کاری (بازهٔ زمانی + منطقه) — شکل `WorkBlock` در mock.ts */
class BlockController extends Controller
{
    public function index(Request $r)
    {
        $me = $r->user()->id;

        return response()->json(['data' => WorkBlock::withCount('reservations')
            ->with(['reservations' => fn ($q) => $q->where('users.id', $me)])
            ->where('starts_at', '>', now())->where('starts_at', '<', now()->addDays(3))->orderBy('starts_at')->get()
            ->map(fn (WorkBlock $b) => [
                'id' => $b->id, 'zoneId' => $b->zone_id, 'startsAt' => $b->starts_at->toIso8601String(), 'endsAt' => $b->ends_at->toIso8601String(),
                'capacity' => $b->capacity, 'taken' => $b->reservations_count, 'bonus' => $b->bonus, 'reserved' => $b->reservations->isNotEmpty(),
            ])]);
    }

    public function toggle(Request $r, WorkBlock $block)
    {
        DB::transaction(function () use ($r, $block) {
            $b = WorkBlock::whereKey($block->id)->lockForUpdate()->first();
            if ($b->reservations()->where('users.id', $r->user()->id)->exists()) {
                $b->reservations()->detach($r->user()->id);

                return;
            }
            if ($b->reservations()->count() >= $b->capacity) {
                throw new ApiException('ظرفیت این بلوک پر است');
            }
            $b->reservations()->attach($r->user()->id);
        });

        return $this->index($r);
    }
}
