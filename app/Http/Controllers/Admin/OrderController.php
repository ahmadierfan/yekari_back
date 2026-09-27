<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Audit;
use App\Services\OrderService;
use App\Services\WalletService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    private const WITH = ['courier.courierProfile', 'customer', 'attachments', 'costCenter', 'organization'];

    public function index(Request $r)
    {
        $q = Order::with(self::WITH)->where('status', '!=', 'draft')->latest('id')
            ->when($r->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($r->query('type'), fn ($q, $t) => $q->where('mission_type', $t))
            ->when($r->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%$s%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%$s%")->orWhere('mobile', 'like', "%$s%"))))
            ->when($r->integer('customerId'), fn ($q, $id) => $q->where('customer_id', $id))
            ->when($r->integer('courierId'), fn ($q, $id) => $q->where('courier_id', $id));

        return OrderResource::collection($q->paginate(min(100, $r->integer('perPage', 25))));
    }

    public function show(Order $order)
    {
        return new OrderResource($order->load(array_merge(self::WITH, ['events'])));
    }

    /** گفت‌وگوی مأموریت — فقط‌خواندنی برای پنل */
    public function chat(Order $order)
    {
        return ChatMessageResource::collection($order->messages);
    }

    public function cancel(Request $r, Order $order)
    {
        $reason = $r->validate(['reason' => 'required|string|max:255'])['reason'];
        $this->orders->cancel($order, $r->user()->id, $reason, byStaff: true);
        Audit::log('update', "مأموریت {$order->code} توسط پنل لغو شد", $order, ['reason' => $reason]);

        return new OrderResource($order->fresh(self::WITH));
    }

    /**
     * بستن شکایت: `complete` (کار انجام‌شده حساب می‌شود و پول تسویه) یا `cancel` (وجه
     * مشتری آزاد). `refund` اختیاری: جبران خسارت به کیف پول مشتری بعد از تکمیل.
     */
    public function resolveDispute(Request $r, Order $order, WalletService $wallets)
    {
        $d = $r->validate(['outcome' => 'required|in:complete,cancel,resume', 'refund' => 'nullable|integer|min:0', 'note' => 'nullable|string|max:255']);
        if ($order->status !== 'disputed') {
            throw new ApiException('مأموریت در حالت شکایت نیست');
        }
        $this->authorize('orders.disputes');
        match ($d['outcome']) {
            'complete' => $this->orders->finish($order, $r->user()->id),
            'cancel' => $this->orders->cancel($order, $r->user()->id, $d['note'] ?? 'بستن شکایت', byStaff: true),
            'resume' => $this->orders->resolveIssue($order, $r->user()->id),
        };
        if (! empty($d['refund'])) {
            $this->authorize('finance.adjust');
            $wallets->credit($wallets->for($order->customer), 'refund', $d['refund'], "جبران خسارت مأموریت {$order->code}", $order, $r->user()->id);
            Audit::log('money', "جبران {$d['refund']} تومان برای مأموریت {$order->code}", $order);
        }
        Audit::log('update', "شکایت مأموریت {$order->code} بسته شد ({$d['outcome']})", $order, ['note' => $d['note'] ?? null]);

        return new OrderResource($order->fresh(self::WITH));
    }
}
