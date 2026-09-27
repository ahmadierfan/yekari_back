<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Domain;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Address;
use App\Models\MissionType;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    private const WITH = ['courier.courierProfile', 'attachments', 'costCenter', 'organization'];

    public function index(Request $r)
    {
        $q = $r->user()->orders()->with(self::WITH)->where('status', '!=', 'draft')->latest('id');
        if ($r->query('scope') === 'active') {
            $q->whereIn('status', array_merge(Domain::LIVE, ['disputed']));
        } elseif ($r->query('scope') === 'history') {
            $q->whereNotIn('status', array_merge(Domain::LIVE, ['disputed']));
        }

        return OrderResource::collection($q->paginate(min(50, $r->integer('perPage', 20))));
    }

    public function show(Request $r, Order $order)
    {
        $this->authorize('customerAct', $order);

        return new OrderResource($order->load(array_merge(self::WITH, ['events'])));
    }

    /** برآورد زندهٔ صفحهٔ تأیید ویزارد */
    public function quote(Request $r, PricingService $pricing)
    {
        $d = $r->validate([
            'type' => 'required|string', 'pickup' => 'required', 'dropoff' => 'nullable', 'promo' => 'nullable|string|max:32',
            'payMethod' => 'nullable|string',
        ]);
        $type = MissionType::where('active', true)->findOrFail($d['type']);
        $point = function ($v) use ($r) {
            if ($v === null) {
                return null;
            }
            if (is_numeric($v) || isset($v['id'])) {
                $a = Address::where('user_id', $r->user()->id)->findOrFail(is_numeric($v) ? $v : $v['id']);

                return ['lat' => (float) $a->lat, 'lng' => (float) $a->lng];
            }

            return ['lat' => (float) ($v['lat'] ?? 0), 'lng' => (float) ($v['lng'] ?? 0)];
        };
        $org = ($d['payMethod'] ?? null) === 'corporate' ? $r->user()->activeMembership()?->organization : null;

        return response()->json($pricing->quote($type, $point($d['pickup']), $point($d['dropoff'] ?? null), $d['promo'] ?? null, $r->user(), $org));
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'type' => 'required|string',
            'pickup' => 'required',
            'dropoff' => 'nullable',
            'description' => 'required|string|min:6|max:2000',
            'note' => 'nullable|string|max:1000',
            'budgetCap' => 'nullable|integer|min:0|max:100000000',
            'payMethod' => ['required', Rule::in(Domain::PAY_METHODS)],
            'costCenterId' => 'nullable|integer',
            'promo' => 'nullable|string|max:32',
            'scheduledFrom' => 'nullable|date|after:now',
            'scheduledTo' => 'nullable|date|after:scheduledFrom',
        ]);
        $res = $this->orders->create($r->user(), $d);

        return response()->json([
            'data' => new OrderResource($res['order']->load(self::WITH)),
            'paymentUrl' => $res['payment_url'],
        ], 201);
    }

    public function cancel(Request $r, Order $order)
    {
        $this->authorize('customerAct', $order);
        $this->orders->cancel($order, $r->user()->id, $r->validate(['reason' => 'nullable|string|max:255'])['reason'] ?? null);

        return new OrderResource($order->fresh(self::WITH));
    }

    public function rate(Request $r, Order $order)
    {
        $this->authorize('customerAct', $order);
        $d = $r->validate([
            'stars' => 'required|integer|between:1,5', 'tags' => 'array', 'tags.*' => 'string|max:40',
            'note' => 'nullable|string|max:500', 'tip' => 'nullable|integer|min:0|max:5000000',
        ]);
        $this->orders->rate($order, $d['stars'], $d['tags'] ?? [], $d['note'] ?? null, (int) ($d['tip'] ?? 0));

        return new OrderResource($order->fresh(self::WITH));
    }

    /** عکس/نسخهٔ پیوست ویزارد — تا قبل از شروع کار */
    public function attach(Request $r, Order $order)
    {
        $this->authorize('customerAct', $order);
        if (! in_array($order->status, ['draft', 'searching', 'accepted', 'to_pickup'], true)) {
            throw new ApiException('بعد از شروع کار نمی‌شود پیوست اضافه کرد');
        }
        $d = $r->validate(['kind' => 'required|in:photo,prescription', 'file' => 'required|image|max:8192']);
        $order->attachments()->create([
            'uploaded_by' => $r->user()->id, 'kind' => $d['kind'], 'path' => $r->file('file')->store("orders/{$order->id}", 'public'),
        ]);

        return new OrderResource($order->fresh(self::WITH));
    }

    public function report(Request $r, Order $order)
    {
        $this->authorize('customerAct', $order);
        $this->orders->reportIssue($order, $r->user()->id, $r->validate(['reason' => 'required|string|max:255'])['reason']);

        return new OrderResource($order->fresh(self::WITH));
    }
}
