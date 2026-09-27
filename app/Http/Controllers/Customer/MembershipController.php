<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use Illuminate\Http\Request;

/** «حساب سازمانی من» — شکل `CorporateMembership` در mock.ts؛ بدون هیچ اکشن مدیریتی */
class MembershipController extends Controller
{
    public function show(Request $r, WalletService $wallets)
    {
        $m = $r->user()->activeMembership();
        if (! $m) {
            return response()->json(['data' => null]);
        }
        $org = $m->organization;
        $used = $org->usedThisMonth();

        return response()->json(['data' => [
            'organizationId' => $org->id,
            'company' => $org->name,
            'role' => $m->role,
            'billingModel' => $org->billing_model,
            'ceiling' => (int) $org->monthly_ceiling,
            'used' => $used,
            'myLimit' => $m->monthly_limit,
            'myUsed' => $org->usedThisMonth($r->user()->id),
            'balance' => $org->isPrepaid() ? (int) $wallets->for($org)->balance : 0,
            'costCenters' => $org->costCenters()->where('active', true)->get(['id', 'title']),
            'teammates' => $org->members()->where('active', true)->where('user_id', '!=', $r->user()->id)->with('user:id,name')->get()
                ->map(fn ($x) => ['name' => $x->user->name, 'spent' => $org->usedThisMonth($x->user_id)]),
        ]]);
    }
}
