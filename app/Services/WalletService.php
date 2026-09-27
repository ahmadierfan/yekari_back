<?php

namespace App\Services;

use App\Domain\Domain;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * تنها نویسندهٔ موجودی. هر تغییر:
 *  - داخل تراکنش DB و با قفل ردیف کیف پول (دو درخواست هم‌زمان نمی‌توانند یک پول را دوبار خرج کنند)
 *  - همراه یک ردیف دفتر با موجودی بعد از تغییر (حسابرسی و بازسازی)
 */
class WalletService
{
    public function for(Model $owner): Wallet
    {
        return Wallet::firstOrCreate(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()]);
    }

    /**
     * @param  int  $balanceDelta  تغییر موجودی قابل استفاده
     * @param  int  $heldDelta  تغییر مبلغ بلوکه‌شده
     */
    public function move(
        Wallet $wallet, string $kind, int $amount, int $balanceDelta, int $heldDelta, string $title,
        ?Order $order = null, ?int $actorId = null, array $meta = [], bool $allowNegative = false,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new ApiException('مبلغ نامعتبر است');
        }

        return DB::transaction(function () use ($wallet, $kind, $amount, $balanceDelta, $heldDelta, $title, $order, $actorId, $meta, $allowNegative) {
            /** @var Wallet $w */
            $w = Wallet::whereKey($wallet->id)->lockForUpdate()->first();
            $balance = $w->balance + $balanceDelta;
            $held = $w->held + $heldDelta;
            if (($balance < 0 && ! $allowNegative) || $held < 0) {
                throw new ApiException('موجودی کافی نیست');
            }
            $w->forceFill(['balance' => $balance, 'held' => $held])->save();
            $wallet->setRawAttributes($w->getAttributes(), true);

            return $w->transactions()->create([
                'kind' => $kind, 'amount' => $amount, 'balance_after' => $balance, 'held_after' => $held,
                'title' => $title, 'order_id' => $order?->id, 'actor_id' => $actorId, 'meta' => $meta ?: null,
            ]);
        });
    }

    public function credit(Wallet $w, string $kind, int $amount, string $title, ?Order $o = null, ?int $actor = null, array $meta = []): WalletTransaction
    {
        return $this->move($w, $kind, $amount, $amount, 0, $title, $o, $actor, $meta);
    }

    public function debit(Wallet $w, string $kind, int $amount, string $title, ?Order $o = null, ?int $actor = null, array $meta = [], bool $allowNegative = false): WalletTransaction
    {
        return $this->move($w, $kind, $amount, -$amount, 0, $title, $o, $actor, $meta, $allowNegative);
    }

    /** پرداخت امن: از «قابل استفاده» به «بلوکه‌شده» */
    public function hold(Wallet $w, int $amount, string $title, ?Order $o = null, ?int $actor = null): WalletTransaction
    {
        return $this->move($w, 'hold', $amount, -$amount, $amount, $title, $o, $actor);
    }

    /** آزادسازی بلوکه: `spend` از آن خرج می‌شود و باقی به «قابل استفاده» برمی‌گردد */
    public function settleHold(Wallet $w, int $held, int $spend, string $title, ?Order $o = null, ?int $actor = null): void
    {
        DB::transaction(function () use ($w, $held, $spend, $title, $o, $actor) {
            $spendFromHold = min($held, $spend);
            $back = $held - $spendFromHold;
            if ($spendFromHold > 0) {
                $this->move($w, 'spend', $spendFromHold, 0, -$spendFromHold, $title, $o, $actor);
            }
            if ($back > 0) {
                $this->move($w, 'release', $back, $back, -$back, 'آزادسازی باقی‌ماندهٔ وجه امانی', $o, $actor);
            }
            // مبلغ نهایی از سقف بلوکه بیشتر شد (مثلاً اضافه‌کاری): مابه‌التفاوت از موجودی — حتی منفی،
            // چون کار انجام شده و بدهی مشتری واقعی است؛ پنل بدهکارها را می‌بیند.
            if ($spend > $held) {
                $this->debit($w, 'spend', $spend - $held, $title.' (مابه‌التفاوت)', $o, $actor, allowNegative: true);
            }
        });
    }

    public function sign(string $kind): int
    {
        return Domain::TX_SIGN[$kind] ?? 0;
    }
}
