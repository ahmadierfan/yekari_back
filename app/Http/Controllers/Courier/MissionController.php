<?php

namespace App\Http\Controllers\Courier;

use App\Domain\Domain;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderOffer;
use App\Services\CourierService;
use App\Services\DispatchService;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MissionController extends Controller
{
    public function __construct(private OrderService $orders) {}

    private const WITH = ['attachments', 'customer', 'events'];

    /**
     * وضعیت لحظه‌ای اپ پیک در یک درخواست: پیشنهاد جاری (با ثانیهٔ باقی‌مانده) و مأموریت
     * فعال. تا سوکت بیاید، اپ این را هر چند ثانیه poll می‌کند.
     */
    public function current(Request $r, DispatchService $dispatch)
    {
        $dispatch->sweep();
        $me = $r->user()->id;
        $active = Order::with(self::WITH)->where('courier_id', $me)->whereIn('status', array_merge(Domain::LIVE, ['disputed']))->first();
        $offer = $active ? null : OrderOffer::with('order.attachments', 'order.customer')->where('courier_id', $me)
            ->where('status', 'pending')->where('expires_at', '>', now())->latest('id')->first();

        return response()->json([
            'active' => $active ? new OrderResource($active) : null,
            'offer' => $offer ? [
                'id' => $offer->id,
                'order' => new OrderResource($offer->order),
                'payout' => (int) $offer->payout,
                'toPickupKm' => $offer->to_pickup_km,
                'routeKm' => $offer->order->distance_km,
                'window' => (int) config('yekari.offer_window_seconds'),
                'left' => max(0, (int) now()->diffInSeconds($offer->expires_at, false)),
            ] : null,
        ]);
    }

    public function accept(Request $r, OrderOffer $offer)
    {
        return new OrderResource($this->orders->accept($offer, $r->user())->load(self::WITH));
    }

    public function decline(Request $r, OrderOffer $offer)
    {
        $this->orders->decline($offer, $r->user());

        return response()->noContent();
    }

    public function advance(Request $r, Order $order)
    {
        $this->authorize('courierAct', $order);

        return new OrderResource($this->orders->advance($order, $r->user())->fresh(self::WITH));
    }

    /** فاکتور خرید: مبلغ + عکس. نگهبان اول پیشرفت مرحله */
    public function expense(Request $r, Order $order)
    {
        $this->authorize('courierAct', $order);
        $d = $r->validate(['amount' => 'required|integer|min:0|max:100000000', 'file' => 'required|image|max:8192']);
        $this->orders->logExpense($order, $d['amount']);
        $order->attachments()->create([
            'uploaded_by' => $r->user()->id, 'kind' => 'receipt', 'amount' => $d['amount'],
            'path' => $r->file('file')->store("orders/{$order->id}", 'public'),
        ]);

        return new OrderResource($order->fresh(self::WITH));
    }

    /** مدرک تحویل/انجام کار. نگهبان دوم */
    public function proof(Request $r, Order $order)
    {
        $this->authorize('courierAct', $order);
        $r->validate(['files' => 'required|array|min:1|max:6', 'files.*' => 'image|max:8192']);
        foreach ($r->file('files') as $f) {
            $order->attachments()->create(['uploaded_by' => $r->user()->id, 'kind' => 'proof', 'path' => $f->store("orders/{$order->id}", 'public')]);
        }

        return new OrderResource($order->fresh(self::WITH));
    }

    public function issue(Request $r, Order $order)
    {
        $this->authorize('courierAct', $order);
        $d = $r->validate(['reason' => ['required', Rule::in(Domain::COURIER_ISSUES)], 'note' => 'nullable|string|max:255']);
        $this->orders->reportIssue($order, $r->user()->id, trim($d['reason'].' '.($d['note'] ?? '')));

        return new OrderResource($order->fresh(self::WITH));
    }

    public function resolve(Request $r, Order $order)
    {
        $this->authorize('courierAct', $order);
        $this->orders->resolveIssue($order, $r->user()->id);

        return new OrderResource($order->fresh(self::WITH));
    }

    public function abandon(Request $r, Order $order)
    {
        $this->authorize('courierAct', $order);
        $this->orders->abandon($order, $r->user(), $r->validate(['reason' => 'nullable|string|max:255'])['reason'] ?? null);

        return response()->noContent();
    }

    public function show(Request $r, Order $order)
    {
        $this->authorize('courierAct', $order);

        return new OrderResource($order->load(self::WITH));
    }

    public function history(Request $r)
    {
        return OrderResource::collection(Order::with('attachments', 'customer')->where('courier_id', $r->user()->id)
            ->whereIn('status', ['completed', 'cancelled'])->latest('finished_at')->paginate(20));
    }

    public function earnings(Request $r, CourierService $svc)
    {
        return response()->json($svc->earnings($r->user()));
    }
}
