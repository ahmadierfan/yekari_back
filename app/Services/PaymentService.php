<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\GatewayTransaction;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\PaymentGateway;
use Illuminate\Support\Facades\DB;

/**
 * شارژ کیف پول از درگاه — الگوی MGatewaytransactionController پروژهٔ مرجع:
 * یک ردیف تراکنش قبل از رفتن به درگاه، تأیید idempotent در callback (درگاه ممکن
 * است callback را دوبار بزند)، و برگشت کاربر به اپ.
 */
class PaymentService
{
    public function __construct(private PaymentGateway $gateway, private WalletService $wallets) {}

    /** @return array{url: string, transaction: GatewayTransaction} */
    public function start(User $user, Wallet $wallet, int $amount, string $app, ?Order $order = null): array
    {
        if ($amount < 1000) {
            throw new ApiException('حداقل مبلغ پرداخت ۱٬۰۰۰ تومان است');
        }
        $tx = GatewayTransaction::create([
            'user_id' => $user->id, 'wallet_id' => $wallet->id, 'gateway' => $this->gateway->name(),
            'amount' => $amount, 'order_id' => $order?->id,
            // شارژِ کمبودِ یک سفارش به صفحهٔ «ثبت شد» همان سفارش برمی‌گردد، نه کیف پول
            'return_url' => $order
                ? config('yekari.payment.return_urls.order').'?id='.$order->id
                : config("yekari.payment.return_urls.$app"),
        ]);
        $res = $this->gateway->request($amount, 'YKP-'.$tx->id, route('payment.callback', ['gateway' => $this->gateway->name()]));
        if (! $res['ok']) {
            $tx->update(['status' => 'cancelled', 'meta' => ['error' => $res['error'] ?? null]]);
            throw new ApiException($res['error'] ?? 'خطا در اتصال به درگاه', 502);
        }
        $tx->update(['authority' => $res['authority']]);

        return ['url' => $res['link'], 'transaction' => $tx];
    }

    /** @return string آدرس برگشت به اپ */
    public function callback(array $query): string
    {
        $authority = $query['trackId'] ?? $query['Authority'] ?? $query['authority'] ?? null;
        $tx = $authority ? GatewayTransaction::where('authority', $authority)->first() : null;
        if (! $tx) {
            return (string) config('yekari.payment.return_urls.customer').'?status=cancel';
        }
        $back = function (string $s) use ($tx) {
            $url = $tx->return_url ?: (string) config('yekari.payment.return_urls.customer');

            return $url.(str_contains($url, '?') ? '&' : '?')."status=$s&tx={$tx->id}";
        };

        if ($tx->status === 'paid') {
            return $back('ok');
        }
        $res = $this->gateway->verify($query, (int) $tx->amount);
        if (! $res['ok']) {
            $tx->update(['status' => 'cancelled']);

            return $back('cancel');
        }

        DB::transaction(function () use ($tx, $res) {
            $locked = GatewayTransaction::whereKey($tx->id)->lockForUpdate()->first();
            if ($locked->status === 'paid') {
                return;
            }
            $locked->update(['status' => 'paid', 'paid_at' => now(), 'ref_id' => $res['ref_id'] ?? null]);
            $this->wallets->credit($locked->wallet, 'topup', (int) $locked->amount, 'شارژ از درگاه بانکی', actor: $locked->user_id, meta: ['gateway' => $locked->gateway, 'ref' => $res['ref_id'] ?? null]);
            if ($locked->order_id) {
                app(OrderService::class)->afterTopup(Order::find($locked->order_id));
            }
        });

        return $back('ok');
    }
}
