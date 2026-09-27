<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Domain;
use App\Http\Controllers\Controller;
use App\Models\CourierProfile;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** اعداد داشبورد و بج‌های سایدبار — هر عدد از دیتابیس، نه ثابت */
class DashboardController extends Controller
{
    public function index(Request $r)
    {
        $u = $r->user();
        $revenue = $u->can('reports.view') ? $this->revenue(7) : null;

        return response()->json([
            'liveOrders' => $u->can('orders.view') ? Order::whereIn('status', Domain::LIVE)->count() : null,
            'disputed' => $u->can('orders.view') ? Order::where('status', 'disputed')->count() : null,
            'pendingPayouts' => $u->can('finance.view') ? Withdrawal::where('status', 'pending')->count() : null,
            'pendingCouriers' => $u->can('couriers.view') ? CourierProfile::where('state', 'pending')->count() : null,
            'onlineCouriers' => $u->can('couriers.view') ? CourierProfile::where('online', true)->count() : null,
            'blockedUsers' => $u->can('users.view') ? User::where('status', 'blocked')->count() : null,
            'openTickets' => $u->can('tickets.view') ? Ticket::where('status', 'open')->count() : null,
            'revenue' => $revenue,
        ]);
    }

    public function revenue(int $days = 7): array
    {
        $from = Carbon::today()->subDays($days - 1);
        $rows = Order::where('status', 'completed')->where('finished_at', '>=', $from)->get(['finished_at', 'price_service', 'price_distance', 'price_waiting', 'price_goods', 'price_discount', 'commission_amount']);

        return collect(range($days - 1, 0))->map(function ($d) use ($rows) {
            $day = Carbon::today()->subDays($d);
            $dayRows = $rows->filter(fn ($o) => $o->finished_at->isSameDay($day));

            return [
                'date' => $day->toDateString(),
                'gross' => (int) $dayRows->sum(fn ($o) => $o->total()),
                'commission' => (int) $dayRows->sum('commission_amount'),
                'orders' => $dayRows->count(),
            ];
        })->values()->all();
    }

    public function reports(Request $r)
    {
        $days = min(90, max(7, $r->integer('days', 30)));

        return response()->json([
            'revenue' => $this->revenue($days),
            'byType' => Order::where('status', 'completed')->where('finished_at', '>=', Carbon::today()->subDays($days))
                ->selectRaw('mission_type as type, count(*) as orders, sum(commission_amount) as commission')->groupBy('mission_type')->get(),
            'statusCounts' => Order::where('created_at', '>=', Carbon::today()->subDays($days))->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }
}
