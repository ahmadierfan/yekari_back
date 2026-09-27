<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Domain;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** قراردادهای سازمانی از دید اپراتور یکاری — شکل `Contract` در adminMock.ts */
class ContractController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    private function row(Organization $o): array
    {
        return [
            'id' => $o->id, 'company' => $o->name, 'status' => $o->status, 'billingModel' => $o->billing_model,
            'discount' => $o->discount_percent, 'ceiling' => (int) $o->monthly_ceiling, 'used' => $o->usedThisMonth(),
            'seats' => $o->seats, 'members' => $o->members()->where('active', true)->count(),
            'costCenters' => $o->costCenters()->where('active', true)->pluck('title'),
            'from' => $o->starts_on?->toDateString(), 'to' => $o->ends_on?->toDateString(), 'contact' => $o->contact_phone,
            'balance' => $o->isPrepaid() ? (int) $this->wallets->for($o)->balance : null,
            'managers' => $o->members()->where('role', 'manager')->with('user:id,name,mobile')->get()->map(fn ($m) => ['id' => $m->user_id, 'name' => $m->user->name, 'mobile' => $m->user->mobile]),
        ];
    }

    public function index()
    {
        return response()->json(['data' => Organization::latest('id')->get()->map(fn ($o) => $this->row($o))]);
    }

    public function show(Organization $organization)
    {
        return response()->json($this->row($organization));
    }

    public function store(Request $r)
    {
        return $this->save($r, new Organization);
    }

    public function update(Request $r, Organization $organization)
    {
        return $this->save($r, $organization);
    }

    private function save(Request $r, Organization $o)
    {
        $req = $o->exists ? 'sometimes' : 'required';
        $d = $r->validate([
            'company' => "$req|string|max:120", 'billingModel' => "$req|in:prepaid,postpaid", 'discount' => 'sometimes|integer|min:0|max:50',
            'ceiling' => 'sometimes|integer|min:0', 'seats' => 'sometimes|integer|min:0', 'from' => 'sometimes|nullable|date', 'to' => 'sometimes|nullable|date',
            'contact' => 'sometimes|nullable|string|max:20', 'nationalId' => 'sometimes|nullable|string|max:16', 'economicCode' => 'sometimes|nullable|string|max:16',
            'managerMobile' => "$req|string",
        ]);
        $map = ['company' => 'name', 'billingModel' => 'billing_model', 'discount' => 'discount_percent', 'ceiling' => 'monthly_ceiling', 'seats' => 'seats',
            'from' => 'starts_on', 'to' => 'ends_on', 'contact' => 'contact_phone', 'nationalId' => 'national_id', 'economicCode' => 'economic_code'];

        DB::transaction(function () use ($d, $map, $o) {
            $o->fill(collect($d)->only(array_keys($map))->mapWithKeys(fn ($v, $k) => [$map[$k] => $v])->all())->save();
            if (! empty($d['managerMobile'])) {
                $mobile = Domain::normalizeMobile($d['managerMobile']);
                if (! Domain::isValidMobile($mobile)) {
                    throw new ApiException('شمارهٔ مدیر سازمان معتبر نیست', 422, ['managerMobile' => ['نامعتبر']]);
                }
                $u = User::firstOrCreate(['mobile' => $mobile]);
                $u->assignRole('customer');
                $o->members()->updateOrCreate(['user_id' => $u->id], ['role' => 'manager', 'active' => true]);
            }
        });
        Audit::log($o->wasRecentlyCreated ? 'create' : 'update', "قرارداد سازمانی «{$o->name}» ".($o->wasRecentlyCreated ? 'ایجاد' : 'ویرایش').' شد', $o);

        return response()->json($this->row($o->fresh()), $o->wasRecentlyCreated ? 201 : 200);
    }

    public function setStatus(Request $r, Organization $organization)
    {
        $d = $r->validate(['status' => 'required|in:draft,active,expired']);
        $organization->update(['status' => $d['status']]);
        Audit::log('update', "قرارداد «{$organization->name}» ".['active' => 'فعال', 'expired' => 'منقضی', 'draft' => 'پیش‌نویس'][$d['status']].' شد', $organization);

        return response()->json($this->row($organization));
    }

    /** شارژ اعتبار سازمان پیش‌پرداخت بعد از واریز (خارج از درگاه) */
    public function credit(Request $r, Organization $organization)
    {
        $d = $r->validate(['amount' => 'required|integer|min:1000', 'reference' => 'required|string|max:64']);
        if (! $organization->isPrepaid()) {
            throw new ApiException('حساب پس‌پرداخت اعتبار ندارد');
        }
        $this->wallets->credit($this->wallets->for($organization), 'topup', $d['amount'], 'شارژ اعتبار سازمان', actor: $r->user()->id, meta: ['reference' => $d['reference']]);
        Audit::log('money', 'شارژ '.number_format($d['amount'])." تومان برای «{$organization->name}»", $organization);

        return response()->json($this->row($organization));
    }
}
