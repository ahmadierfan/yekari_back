<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TxResource;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Audit;
use App\Services\Notifier;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    /** صف برداشت مشتری و تسویهٔ پیک — شکل `AdminPayout` */
    public function withdrawals(Request $r)
    {
        $q = Withdrawal::with('user')->latest('id')
            ->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($r->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->paginate(min(100, $r->integer('perPage', 25)));
        $q->getCollection()->transform(fn (Withdrawal $w) => [
            'id' => $w->id, 'kind' => $w->kind, 'courier' => $w->user->name, 'userId' => $w->user_id, 'amount' => (int) $w->amount,
            'at' => $w->created_at->toIso8601String(), 'card' => $w->pan, 'status' => $w->status, 'reference' => $w->reference,
        ]);

        return $q;
    }

    /** تأیید (با شمارهٔ پیگیری واریز) یا رد (مبلغ به کیف پول برمی‌گردد) */
    public function resolveWithdrawal(Request $r, Withdrawal $withdrawal)
    {
        $d = $r->validate(['ok' => 'required|boolean', 'reference' => 'nullable|string|max:64', 'reason' => 'nullable|string|max:255']);
        DB::transaction(function () use ($d, $withdrawal, $r) {
            $w = Withdrawal::whereKey($withdrawal->id)->lockForUpdate()->first();
            if ($w->status !== 'pending') {
                throw new ApiException('این درخواست قبلاً بررسی شده است', 409);
            }
            $w->update([
                'status' => $d['ok'] ? 'done' : 'rejected', 'reference' => $d['reference'] ?? null,
                'reject_reason' => $d['reason'] ?? null, 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(),
            ]);
            if (! $d['ok']) {
                $this->wallets->credit($this->wallets->for($w->user), 'refund', (int) $w->amount, 'برگشت درخواست تسویهٔ ردشده', actor: $r->user()->id);
            }
            $app = $w->kind === 'payout' ? 'courier' : 'customer';
            Notifier::send($w->user_id, $app, $d['ok'] ? 'واریز انجام شد' : 'درخواست برداشت رد شد', $d['reason'] ?? null, 'banknote', $d['ok'] ? 'ok' : 'danger');
            Audit::log('money', ($w->kind === 'payout' ? 'تسویهٔ ' : 'برداشت ').number_format($w->amount)." تومان برای {$w->user->name} ".($d['ok'] ? 'تأیید' : 'رد').' شد', $w);
        });

        return response()->json(['status' => $withdrawal->fresh()->status]);
    }

    public function userTransactions(User $user)
    {
        return TxResource::collection($this->wallets->for($user)->transactions()->with('order:id,code')->paginate(50));
    }

    /** اصلاح دستی موجودی (جبران، جریمه) — همیشه با دلیل و لاگ */
    public function adjust(Request $r, User $user)
    {
        $d = $r->validate(['kind' => 'required|in:bonus,refund,penalty', 'amount' => 'required|integer|min:1|max:100000000', 'reason' => 'required|string|max:255']);
        $w = $this->wallets->for($user);
        $d['kind'] === 'penalty'
            ? $this->wallets->debit($w, 'penalty', $d['amount'], $d['reason'], actor: $r->user()->id, allowNegative: true)
            : $this->wallets->credit($w, $d['kind'], $d['amount'], $d['reason'], actor: $r->user()->id);
        Audit::log('money', "اصلاح موجودی {$user->name}: {$d['kind']} ".number_format($d['amount']).' تومان', $user, ['reason' => $d['reason']]);

        return response()->json(['balance' => (int) $w->fresh()->balance]);
    }
}
