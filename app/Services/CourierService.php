<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;

class CourierService
{
    public function __construct(private WalletService $wallets) {}

    /**
     * پاداش پلکانی روزانه. پله‌ها هدف تجمعی‌اند؛ با رسیدن به هر پله فقط **تفاوت** با
     * پلهٔ قبلی پرداخت می‌شود و هر پله فقط یک‌بار در روز.
     */
    public function payBonuses(User $courier): void
    {
        $done = $this->completedToday($courier)->count();
        $wallet = $this->wallets->for($courier);
        $paid = WalletTransaction::where('wallet_id', $wallet->id)->where('kind', 'bonus')
            ->where('created_at', '>=', Carbon::today())->get()
            ->map(fn ($t) => $t->meta['tier'] ?? null)->filter()->all();
        $prev = 0;
        foreach (Setting::get('bonus_tiers') as $tier) {
            if ($done >= $tier['missions'] && ! in_array($tier['missions'], $paid)) {
                $this->wallets->credit($wallet, 'bonus', $tier['amount'] - $prev, "پاداش {$tier['missions']} مأموریت امروز", meta: ['tier' => $tier['missions']]);
            }
            $prev = $tier['amount'];
        }
    }

    public function completedToday(User $courier)
    {
        return Order::where('courier_id', $courier->id)->where('status', 'completed')->where('finished_at', '>=', Carbon::today());
    }

    /** خلاصهٔ صفحهٔ درآمد: امروز، هفت روز گذشته، پاداش‌ها، موجودی */
    public function earnings(User $courier): array
    {
        $wallet = $this->wallets->for($courier);
        $txs = WalletTransaction::where('wallet_id', $wallet->id)
            ->whereIn('kind', ['earning', 'bonus'])
            ->where('created_at', '>=', Carbon::today()->subDays(6))
            ->get();
        $isIncome = fn ($t) => $t->kind === 'bonus' || in_array($t->meta['source'] ?? '', ['mission', 'tip']);

        $week = collect(range(6, 0))->map(function ($d) use ($txs, $isIncome) {
            $day = Carbon::today()->subDays($d);

            return [
                'date' => $day->toDateString(),
                'amount' => (int) $txs->filter(fn ($t) => $t->created_at->isSameDay($day) && $isIncome($t))->sum('amount'),
            ];
        })->values();

        $today = $this->completedToday($courier)->latest('finished_at')->get();
        $bonuses = $txs->where('kind', 'bonus')->filter(fn ($t) => $t->created_at->isToday())->values();

        return [
            'balance' => (int) $wallet->balance,
            'todayTotal' => $week->last()['amount'],
            'bonusToday' => (int) $bonuses->sum('amount'),
            'bonusTiers' => Setting::get('bonus_tiers'),
            'done' => $today->map(fn (Order $o) => [
                'id' => $o->id, 'code' => $o->code, 'type' => $o->mission_type,
                'at' => $o->finished_at?->toIso8601String(), 'payout' => (int) $o->courier_payout, 'tip' => (int) $o->tip ?: null,
            ]),
            'bonuses' => $bonuses->map(fn ($t) => ['id' => $t->id, 'tier' => $t->meta['tier'] ?? null, 'amount' => (int) $t->amount, 'at' => $t->created_at->toIso8601String()]),
            'week' => $week,
        ];
    }
}
