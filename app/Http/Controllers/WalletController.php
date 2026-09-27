<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Exceptions\ApiException;
use App\Http\Resources\CardResource;
use App\Http\Resources\TxResource;
use App\Models\BankCard;
use App\Models\Setting;
use App\Models\Withdrawal;
use App\Services\PaymentService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * کیف پول و کارت‌های بانکی — مشترک بین مشتری (برداشت) و پیک (تسویه). فرق دو اپ
 * فقط در نوع برداشت و حداقل مبلغ است.
 */
class WalletController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    public function show(Request $r)
    {
        $w = $this->wallets->for($r->user());

        return response()->json([
            'balance' => (int) $w->balance, 'held' => (int) $w->held, 'total' => (int) $w->balance + (int) $w->held,
            'withdrawals' => Withdrawal::where('user_id', $r->user()->id)->latest('id')->limit(20)->get()
                ->map(fn ($x) => ['id' => $x->id, 'amount' => (int) $x->amount, 'at' => $x->created_at->toIso8601String(), 'card' => $x->pan, 'status' => $x->status, 'kind' => $x->kind]),
        ]);
    }

    public function transactions(Request $r)
    {
        $w = $this->wallets->for($r->user());

        return TxResource::collection($w->transactions()->with('order:id,code')
            ->when($r->query('kind'), fn ($q, $k) => $q->whereIn('kind', explode(',', $k)))
            ->paginate(min(50, $r->integer('perPage', 30))));
    }

    public function topup(Request $r, PaymentService $payments)
    {
        $d = $r->validate(['amount' => 'required|integer|min:10000|max:500000000']);
        $res = $payments->start($r->user(), $this->wallets->for($r->user()), $d['amount'], 'customer');

        return response()->json(['paymentUrl' => $res['url'], 'transactionId' => $res['transaction']->id]);
    }

    /** مشتری: برداشت؛ پیک: تسویه. مبلغ همان لحظه کسر می‌شود تا دوباره خرج نشود. */
    public function withdraw(Request $r)
    {
        $d = $r->validate(['amount' => 'required|integer|min:1', 'cardId' => 'required|integer']);
        $user = $r->user();
        $isCourier = $r->user()->tokenCan('app:courier');
        $min = $isCourier ? (int) ($user->courierProfile->min_payout ?? 0) : (int) Setting::get('min_withdraw');
        if ($d['amount'] < $min) {
            throw new ApiException('مبلغ کمتر از حداقل مجاز است', 422, ['amount' => ["حداقل $min تومان"]]);
        }
        $card = BankCard::where('user_id', $user->id)->findOrFail($d['cardId']);

        $w = DB::transaction(function () use ($user, $card, $d, $isCourier) {
            $kind = $isCourier ? 'payout' : 'withdraw';
            $this->wallets->debit($this->wallets->for($user), $kind, $d['amount'], ($isCourier ? 'تسویه به ' : 'برداشت به ').$card->bank, actor: $user->id);

            return Withdrawal::create(['user_id' => $user->id, 'bank_card_id' => $card->id, 'kind' => $kind, 'amount' => $d['amount'], 'pan' => $card->pan]);
        });

        return response()->json(['id' => $w->id, 'status' => $w->status], 201);
    }

    public function cards(Request $r)
    {
        return CardResource::collection($r->user()->bankCards()->orderByDesc('is_primary')->get());
    }

    public function addCard(Request $r)
    {
        $d = $r->validate(['pan' => 'required|string', 'owner' => 'required|string|max:80', 'iban' => 'nullable|string|regex:/^IR\d{24}$/']);
        $pan = preg_replace('/\D/', '', strtr($d['pan'], array_combine(mb_str_split('۰۱۲۳۴۵۶۷۸۹'), str_split('0123456789'))));
        if (! Domain::isValidPan($pan)) {
            throw new ApiException('شمارهٔ کارت معتبر نیست', 422, ['pan' => ['شمارهٔ کارت معتبر نیست']]);
        }
        if ($r->user()->bankCards()->where('pan', $pan)->exists()) {
            throw new ApiException('این کارت قبلاً ثبت شده است', 422, ['pan' => ['تکراری']]);
        }
        $card = $r->user()->bankCards()->create([
            'pan' => $pan, 'bank' => Domain::bankOf($pan), 'owner' => trim($d['owner']), 'iban' => $d['iban'] ?? null,
            'is_primary' => ! $r->user()->bankCards()->exists(),
        ]);

        return new CardResource($card);
    }

    public function removeCard(Request $r, BankCard $card)
    {
        abort_unless($card->user_id === $r->user()->id, 404);
        DB::transaction(function () use ($card, $r) {
            $card->delete();
            if ($card->is_primary && ($next = $r->user()->bankCards()->first())) {
                $next->update(['is_primary' => true]);
            }
        });

        return response()->noContent();
    }

    public function primaryCard(Request $r, BankCard $card)
    {
        abort_unless($card->user_id === $r->user()->id, 404);
        DB::transaction(function () use ($card, $r) {
            $r->user()->bankCards()->update(['is_primary' => false]);
            $card->update(['is_primary' => true]);
        });

        return $this->cards($r);
    }
}
