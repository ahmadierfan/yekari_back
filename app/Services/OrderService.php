<?php

namespace App\Services;

use App\Domain\Domain;
use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\CostCenter;
use App\Models\MissionType;
use App\Models\Order;
use App\Models\OrderOffer;
use App\Models\PromoCode;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * چرخهٔ عمر مأموریت و پول آن. همهٔ تغییر وضعیت‌ها از این‌جا می‌گذرند تا قواعد
 * (نگهبان فاکتور/مدرک تحویل، اضافه‌کاری، پرداخت امن، سهم پیک) یک جا باشند.
 */
class OrderService
{
    public function __construct(
        private PricingService $pricing,
        private WalletService $wallets,
        private DispatchService $dispatch,
        private PaymentService $payments,
    ) {}

    /* ───────────────────── ثبت ───────────────────── */

    /**
     * @return array{order: Order, payment_url: ?string}
     */
    public function create(User $user, array $data): array
    {
        $type = MissionType::where('key', $data['type'])->where('active', true)->first()
            ?? throw new ApiException('دستهٔ مأموریت نامعتبر است', 422, ['type' => ['دستهٔ مأموریت نامعتبر است']]);

        $pickup = $this->resolveAddress($user, $data['pickup']);
        $dropoff = isset($data['dropoff']) ? $this->resolveAddress($user, $data['dropoff']) : null;
        if ($type->needs_dropoff && ! $dropoff) {
            throw new ApiException('مقصد برای این دسته لازم است', 422, ['dropoff' => ['مقصد لازم است']]);
        }

        $payMethod = $data['payMethod'];
        $org = null;
        $member = null;
        $costCenterId = null;
        if ($payMethod === 'corporate') {
            $member = $user->activeMembership() ?? throw new ApiException('عضو هیچ حساب سازمانی فعالی نیستی');
            $org = $member->organization;
            if ($org->costCenters()->where('active', true)->exists()) {
                $cc = CostCenter::where('organization_id', $org->id)->where('active', true)->find($data['costCenterId'] ?? 0)
                    ?? throw new ApiException('مرکز هزینه را انتخاب کن', 422, ['costCenterId' => ['مرکز هزینه لازم است']]);
                $costCenterId = $cc->id;
            }
        }

        $q = $this->pricing->quote($type, $pickup, $dropoff, $data['promo'] ?? null, $user, $org);
        $budgetCap = (int) ($data['budgetCap'] ?? 0) ?: null;
        // مبلغ امانی = برآورد خدمات + سقف خرید (مبلغ واقعی کالا تا فاکتور معلوم نیست)
        $holdAmount = $q['total'] + ($budgetCap ?? 0);

        if ($org) {
            $this->checkCorporateLimits($org, $member, $holdAmount);
        }

        return DB::transaction(function () use ($user, $type, $pickup, $dropoff, $data, $q, $budgetCap, $holdAmount, $payMethod, $org, $costCenterId) {
            $order = Order::create([
                'code' => $this->nextCode(),
                'customer_id' => $user->id,
                'mission_type' => $type->key,
                'status' => 'draft',
                'pickup' => $pickup,
                'dropoff' => $dropoff,
                'description' => trim($data['description']),
                'note' => isset($data['note']) ? (trim($data['note']) ?: null) : null,
                'budget_cap' => $budgetCap,
                'distance_km' => $q['distance_km'],
                'price_service' => $q['service'],
                'price_distance' => $q['distance'],
                'price_waiting' => 0,
                'price_discount' => $q['discount'],
                'commission_rate' => $q['commission_rate'],
                // پرداخت امن برای کیف پول/درگاه همیشه روشن است؛ پس‌پرداخت سازمانی چیزی بلوکه نمی‌کند
                'escrow' => ! ($org && ! $org->isPrepaid()),
                'pay_method' => $payMethod,
                'promo_code' => $q['promo'],
                'organization_id' => $org?->id,
                'cost_center_id' => $costCenterId,
                'scheduled_from' => $data['scheduledFrom'] ?? null,
                'scheduled_to' => $data['scheduledTo'] ?? null,
            ]);
            $order->logEvent('draft', $user->id);
            if ($q['promo']) {
                PromoCode::where('code', $q['promo'])->increment('used');
            }

            $paymentUrl = null;
            if ($payMethod === 'corporate') {
                if ($org->isPrepaid()) {
                    $this->hold($order, $this->wallets->for($org), $holdAmount);
                } else {
                    $order->update(['payment_status' => 'invoiced']);
                }
                $this->activate($order);
            } else {
                $wallet = $this->wallets->for($user);
                $shortfall = $holdAmount - (int) $wallet->balance;
                if ($shortfall <= 0) {
                    $this->hold($order, $wallet, $holdAmount);
                    $this->activate($order);
                } elseif ($payMethod === 'gateway') {
                    // کمبود موجودی از درگاه شارژ می‌شود؛ بعد از callback موفق، `afterTopup` سفارش را فعال می‌کند
                    $paymentUrl = $this->payments->start($user, $wallet, $shortfall, 'customer', $order)['url'];
                } else {
                    throw new ApiException('موجودی کیف پول کافی نیست', 422, ['balance' => ['کمبود: '.$shortfall]]);
                }
            }

            return ['order' => $order->fresh(), 'payment_url' => $paymentUrl];
        });
    }

    /** بعد از شارژ موفق درگاه — سفارش پیش‌نویسی که منتظر پرداخت بود فعال می‌شود */
    public function afterTopup(Order $order): void
    {
        if ($order->status !== 'draft') {
            return;
        }
        $hold = $order->total() + (int) $order->budget_cap;
        $this->hold($order, $this->wallets->for($order->customer), $hold);
        $this->activate($order);
    }

    private function hold(Order $order, Wallet $wallet, int $amount): void
    {
        if ($amount > 0) {
            $this->wallets->hold($wallet, $amount, 'بلوکه برای '.$order->type->title, $order, $order->customer_id);
        }
        $order->update(['held_amount' => $amount, 'payment_status' => 'held']);
    }

    private function activate(Order $order): void
    {
        $order->update(['status' => 'searching']);
        $order->logEvent('searching');
        $this->dispatch->dispatch($order);
    }

    private function checkCorporateLimits($org, $member, int $amount): void
    {
        if ($org->monthly_ceiling && $org->usedThisMonth() + $amount > $org->monthly_ceiling) {
            throw new ApiException('سقف اعتبار ماهانهٔ سازمان کافی نیست');
        }
        if ($member->monthly_limit && $org->usedThisMonth($member->user_id) + $amount > $member->monthly_limit) {
            throw new ApiException('سقف مصرف ماهانهٔ شما در حساب سازمانی کافی نیست');
        }
        if ($org->isPrepaid() && (int) $this->wallets->for($org)->balance < $amount) {
            throw new ApiException('اعتبار حساب سازمانی کافی نیست');
        }
    }

    /** آدرس یا با id از دفترچهٔ خود کاربر، یا یک‌بارمصرف با lat/lng */
    private function resolveAddress(User $user, array|int $input): array
    {
        if (is_int($input) || isset($input['id'])) {
            $a = Address::where('user_id', $user->id)->find(is_int($input) ? $input : $input['id'])
                ?? throw new ApiException('آدرس پیدا نشد', 422);

            return $a->snapshot();
        }
        if (! isset($input['lat'], $input['lng'], $input['detail'])) {
            throw new ApiException('آدرس ناقص است', 422);
        }

        return [
            'id' => null, 'title' => $input['title'] ?? '', 'detail' => $input['detail'], 'icon' => $input['icon'] ?? 'map-pin',
            'lat' => (float) $input['lat'], 'lng' => (float) $input['lng'], 'x' => (int) ($input['x'] ?? 50), 'y' => (int) ($input['y'] ?? 36),
        ];
    }

    private function nextCode(): string
    {
        do {
            $code = 'YK-'.random_int(10000, 99999);
        } while (Order::where('code', $code)->exists());

        return $code;
    }

    /* ───────────────────── لغو / امتیاز (مشتری) ───────────────────── */

    public function cancel(Order $order, ?int $actorId, ?string $reason = null, bool $byStaff = false): void
    {
        $allowed = $byStaff ? array_merge(Domain::LIVE, ['disputed']) : ['draft', 'searching', 'accepted', 'to_pickup'];
        if (! in_array($order->status, $allowed, true)) {
            throw new ApiException('در این مرحله لغو ممکن نیست؛ از «گزارش مشکل» استفاده کن');
        }
        DB::transaction(function () use ($order, $actorId, $reason) {
            $this->refundHold($order, $actorId);
            $courierId = $order->courier_id;
            $order->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $order->offers()->where('status', 'pending')->update(['status' => 'expired']);
            $order->logEvent('cancelled', $actorId, $reason);
            if ($courierId) {
                Notifier::send($courierId, 'courier', "مأموریت {$order->code} لغو شد", $reason, 'x-circle', 'danger');
            }
        });
    }

    /**
     * سفارشیِ «در جست‌وجو» که پیکی برایش پیدا نشد. برخلاف `cancel()` وضعیت نهایی
     * `expired` است، نه `cancelled` — قابل تمایز در گزارش‌ها — پس مستقیم اینجا
     * پول را آزاد می‌کنیم و رویداد را ثبت می‌کنیم، به‌جای صدازدن `cancel()` و
     * بازنویسی وضعیتش (که تاریخچهٔ رویداد را با وضعیت نهایی ناهم‌خوان می‌کرد).
     */
    public function expireSearching(Order $order, ?string $reason = null): void
    {
        DB::transaction(function () use ($order, $reason) {
            $this->refundHold($order, null);
            $order->offers()->where('status', 'pending')->update(['status' => 'expired']);
            $order->update(['status' => 'expired']);
            $order->logEvent('expired', null, $reason);
        });
    }

    /** پیش‌نویسی که پرداختش هیچ‌وقت کامل نشد — هنوز پولی بلوکه نشده، فقط وضعیت و رویداد */
    public function expireDraft(Order $order): void
    {
        $order->update(['status' => 'expired']);
        $order->logEvent('expired');
    }

    private function refundHold(Order $order, ?int $actorId): void
    {
        if ($order->payment_status !== 'held' || ! $order->held_amount) {
            return;
        }
        $wallet = $this->payerWallet($order);
        $this->wallets->move($wallet, 'release', $order->held_amount, $order->held_amount, -$order->held_amount, 'آزادسازی وجه سفارش لغوشده', $order, $actorId);
        $order->update(['payment_status' => 'refunded', 'held_amount' => 0]);
    }

    private function payerWallet(Order $order): Wallet
    {
        return $order->organization_id && $order->organization?->isPrepaid()
            ? $this->wallets->for($order->organization)
            : $this->wallets->for($order->customer);
    }

    public function rate(Order $order, int $stars, array $tags, ?string $note, int $tip): void
    {
        if ($order->status !== 'completed') {
            throw new ApiException('فقط مأموریت تکمیل‌شده امتیاز می‌گیرد');
        }
        if ($tip > 0) {
            // انعام فقط بعد از ۴ یا ۵ ستاره — پرسیدنش بعد از تجربهٔ بد توهین است
            if ($stars < 4) {
                throw new ApiException('انعام فقط برای امتیاز ۴ و ۵ است');
            }
            if ($tip < (int) Setting::get('min_tip')) {
                throw new ApiException('مبلغ انعام کمتر از حداقل است');
            }
        }
        DB::transaction(function () use ($order, $stars, $tags, $note, $tip) {
            $firstRating = $order->rating === null;
            $order->update([
                'rating' => $stars, 'rating_tags' => $tags, 'rating_note' => $note ? trim($note) : null,
                'tip' => $order->tip + $tip, // انعام قبلی پس گرفته نمی‌شود، روی هم جمع می‌شود
            ]);
            if ($order->courier_id) {
                $p = $order->courier->courierProfile;
                if ($p && $firstRating) {
                    $p->rating = round(($p->rating * $p->rating_count + $stars) / ($p->rating_count + 1), 2);
                    $p->rating_count++;
                    $p->save();
                }
                if ($tip > 0) {
                    $this->wallets->debit($this->wallets->for($order->customer), 'tip', $tip, 'انعام به انجام‌دهنده', $order, $order->customer_id);
                    $this->wallets->credit($this->wallets->for($order->courier), 'earning', $tip, "انعام مأموریت {$order->code}", $order, meta: ['source' => 'tip']);
                    Notifier::send($order->courier_id, 'courier', 'انعام گرفتی!', "برای مأموریت {$order->code}", 'award', 'accent');
                }
            }
        });
    }

    /* ───────────────────── پیک ───────────────────── */

    public function accept(OrderOffer $offer, User $courier): Order
    {
        if ($offer->courier_id !== $courier->id || $offer->status !== 'pending') {
            throw new ApiException('این پیشنهاد دیگر معتبر نیست', 409);
        }
        if ($offer->expires_at->isPast()) {
            $offer->update(['status' => 'expired']);
            throw new ApiException('مهلت پذیرش تمام شد', 409);
        }

        return DB::transaction(function () use ($offer, $courier) {
            $order = Order::whereKey($offer->order_id)->lockForUpdate()->first();
            if ($order->status !== 'searching' || $order->courier_id) {
                $offer->update(['status' => 'expired']);
                throw new ApiException('این مأموریت را پیک دیگری گرفت', 409);
            }
            $order->update(['courier_id' => $courier->id, 'status' => 'to_pickup', 'started_at' => now()]);
            $offer->update(['status' => 'accepted']);
            $order->offers()->where('id', '!=', $offer->id)->where('status', 'pending')->update(['status' => 'expired']);
            $courier->courierProfile->increment('accepted_count');
            $order->logEvent('accepted', $courier->id);
            $order->logEvent('to_pickup', $courier->id);
            Notifier::send($order->customer_id, 'customer', "{$courier->name} مأموریت را پذیرفت", 'در مسیر مبدأ است.', 'user-check', 'info', "/app/orders/{$order->id}");

            return $order;
        });
    }

    public function decline(OrderOffer $offer, User $courier): void
    {
        if ($offer->courier_id !== $courier->id || $offer->status !== 'pending') {
            return;
        }
        $offer->update(['status' => 'declined']);
        $this->dispatch->dispatch($offer->order);
    }

    public function logExpense(Order $order, int $amount): void
    {
        $this->assertStatus($order, ['to_pickup', 'in_progress']);
        if ($order->budget_cap && $amount > $order->budget_cap) {
            throw new ApiException('مبلغ از سقف خرید بیشتر است؛ «گزارش مشکل» بزن تا مشتری تأیید کند');
        }
        $order->update(['price_goods' => $amount]);
    }

    public function advance(Order $order, User $courier): Order
    {
        $i = array_search($order->status, Domain::COURIER_STEPS, true);
        if ($i === false) {
            throw new ApiException('مأموریت در مرحلهٔ قابل پیشرفت نیست');
        }
        // نگهبان ۱: فاکتور خرید — بدون آن اختلاف مالی بعداً قابل حل نیست
        if ($order->status === 'in_progress' && $order->budget_cap && ! $order->attachments()->where('kind', 'receipt')->exists()) {
            throw new ApiException('اول مبلغ و عکس فاکتور خرید را ثبت کن');
        }
        $next = Domain::COURIER_STEPS[$i + 1] ?? null;
        if (! $next) {
            // نگهبان ۲: مدرک تحویل
            if (! $order->attachments()->where('kind', 'proof')->exists()) {
                throw new ApiException('اول عکس مدرک تحویل را ثبت کن');
            }

            return $this->finish($order, $courier->id);
        }
        $order->update(['status' => $next]);
        $order->logEvent($next, $courier->id);
        Notifier::send($order->customer_id, 'customer', match ($next) {
            'in_progress' => 'انجام‌دهنده به مبدأ رسید',
            'to_dropoff' => 'کار انجام شد؛ در مسیر مقصد است',
        }, null, 'navigation', 'accent', "/app/orders/{$order->id}");

        return $order;
    }

    public function finish(Order $order, ?int $actorId): Order
    {
        return DB::transaction(function () use ($order, $actorId) {
            $order->finished_at = now();
            $type = $order->type;
            // اضافه‌کاری: هرچه بیشتر از استاندارد دسته طول کشید × تعرفهٔ دقیقه‌ای همان دسته
            $actual = $order->started_at ? (int) round($order->started_at->diffInSeconds($order->finished_at) / 60) : 0;
            $extra = max(0, $actual - $type->estimated_minutes);
            if ($extra > 0) {
                $order->extra_minutes = $extra;
                $order->price_waiting += $extra * $type->per_min;
            }
            $payout = PricingService::courierShare($order->serviceTotal(), $order->commission_rate);
            $order->courier_payout = $payout;
            $order->commission_amount = $order->serviceTotal() - $order->price_discount - $payout;
            $order->status = 'completed';
            $order->disputed_from = null;
            $order->save();
            $order->logEvent('completed', $actorId);

            // مشتری: از بلوکه خرج و باقی آزاد (پس‌پرداخت سازمانی: فقط روی فاکتور ماهانه)
            if ($order->payment_status === 'held') {
                $this->wallets->settleHold($this->payerWallet($order), (int) $order->held_amount, $order->total(), 'پرداخت '.$type->title, $order, $actorId);
                $order->update(['payment_status' => 'settled', 'held_amount' => 0]);
            }

            if ($order->courier_id) {
                $courier = $order->courier;
                $w = $this->wallets->for($courier);
                $this->wallets->credit($w, 'earning', $payout, "سهم مأموریت {$order->code}", $order, meta: ['source' => 'mission']);
                if ($order->price_goods > 0) {
                    $this->wallets->credit($w, 'earning', (int) $order->price_goods, "بازپرداخت خرید {$order->code}", $order, meta: ['source' => 'goods']);
                }
                $p = $courier->courierProfile;
                $p->increment('missions_count');
                if ($extra === 0) {
                    $p->increment('on_time_count');
                }
                app(CourierService::class)->payBonuses($courier);
            }
            Notifier::send($order->customer_id, 'customer', "مأموریت {$order->code} تکمیل شد", 'به انجام‌دهنده امتیاز بده.', 'check-circle', 'ok', "/app/orders/{$order->id}");

            return $order;
        });
    }

    public function reportIssue(Order $order, ?int $actorId, string $reason): void
    {
        if ($order->status === 'disputed' || ! $order->isLive() || $order->status === 'searching') {
            throw new ApiException('این مأموریت قابل گزارش نیست');
        }
        $order->update(['disputed_from' => $order->status, 'status' => 'disputed']);
        $order->logEvent('disputed', $actorId, $reason);
    }

    /** مشکل حل شد → برگشت به همان مرحله، نه اول مأموریت */
    public function resolveIssue(Order $order, ?int $actorId): void
    {
        if ($order->status !== 'disputed' || ! $order->disputed_from) {
            throw new ApiException('مأموریت در حالت شکایت نیست');
        }
        $order->update(['status' => $order->disputed_from, 'disputed_from' => null]);
        $order->logEvent($order->status, $actorId, 'مشکل حل شد');
    }

    /** پیک مأموریت را رها می‌کند → دوباره در جست‌وجو برای پیک دیگر */
    public function abandon(Order $order, User $courier, ?string $reason): void
    {
        if (! in_array($order->status, ['to_pickup', 'disputed'], true) || ($order->status === 'disputed' && $order->disputed_from !== 'to_pickup')) {
            throw new ApiException('بعد از شروع کار، رهاکردن فقط از طریق پشتیبانی ممکن است');
        }
        DB::transaction(function () use ($order, $courier, $reason) {
            $order->update(['courier_id' => null, 'status' => 'searching', 'disputed_from' => null, 'started_at' => null, 'price_goods' => 0]);
            $courier->courierProfile->increment('cancelled_count');
            $order->logEvent('searching', $courier->id, 'رهاشده توسط پیک: '.$reason);
            Notifier::send($order->customer_id, 'customer', 'در حال یافتن انجام‌دهندهٔ دیگر', null, 'radar', 'warn', "/app/orders/{$order->id}");
            $this->dispatch->dispatch($order);
        });
    }

    private function assertStatus(Order $order, array $statuses): void
    {
        if (! in_array($order->status, $statuses, true)) {
            throw new ApiException('این کار در مرحلهٔ فعلی مأموریت ممکن نیست');
        }
    }
}
