<?php

namespace App\Http\Controllers\Corporate;

use App\Domain\Domain;
use App\Domain\Period;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Morilog\Jalali\Jalalian;

/**
 * پنل خودِ شرکت طرف قرارداد (مدیر سازمان). همهٔ مسیرها زیر
 * `/corporate/orgs/{organization}` و پشت OrganizationPolicy::manage.
 */
class OrganizationController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    public function mine(Request $r)
    {
        return response()->json(['data' => $r->user()->memberships()->where('role', 'manager')->where('active', true)->with('organization')->get()
            ->map(fn ($m) => ['id' => $m->organization->id, 'name' => $m->organization->name, 'status' => $m->organization->status, 'billingModel' => $m->organization->billing_model])]);
    }

    public function show(Organization $organization)
    {
        $this->authorize('view', $organization);
        $o = $organization;
        [$start, $end] = Period::jalaliMonth();

        return response()->json([
            'id' => $o->id, 'name' => $o->name, 'status' => $o->status, 'billingModel' => $o->billing_model,
            'discount' => $o->discount_percent, 'ceiling' => (int) $o->monthly_ceiling, 'used' => $o->usedThisMonth(),
            'balance' => $o->isPrepaid() ? (int) $this->wallets->for($o)->balance : null,
            'held' => $o->isPrepaid() ? (int) $this->wallets->for($o)->held : null,
            'seats' => $o->seats, 'members' => $o->members()->where('active', true)->count(),
            'from' => $o->starts_on?->toDateString(), 'to' => $o->ends_on?->toDateString(),
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'label' => Period::label($start)],
            'liveOrders' => $o->orders()->whereIn('status', Domain::LIVE)->count(),
        ]);
    }

    /* ── اعضا ─────────────────────────────── */

    public function members(Organization $organization)
    {
        $this->authorize('view', $organization);

        return response()->json(['data' => $organization->members()->with('user:id,name,mobile,last_seen_at')->get()->map(fn (OrganizationMember $m) => [
            'id' => $m->id, 'userId' => $m->user_id, 'name' => $m->user->name, 'mobile' => $m->user->mobile, 'role' => $m->role,
            'monthlyLimit' => $m->monthly_limit, 'active' => $m->active, 'used' => $organization->usedThisMonth($m->user_id),
            'lastSeen' => $m->user->last_seen_at?->toIso8601String(),
        ])]);
    }

    public function addMember(Request $r, Organization $organization)
    {
        $this->authorize('manage', $organization);
        $d = $r->validate(['mobile' => 'required|string', 'name' => 'nullable|string|max:80', 'role' => 'in:member,manager', 'monthlyLimit' => 'nullable|integer|min:0']);
        $mobile = Domain::normalizeMobile($d['mobile']);
        if (! Domain::isValidMobile($mobile)) {
            throw new ApiException('شمارهٔ موبایل معتبر نیست', 422, ['mobile' => ['نامعتبر']]);
        }
        if ($organization->seats && $organization->members()->where('active', true)->count() >= $organization->seats) {
            throw new ApiException('ظرفیت کاربران قرارداد پر است؛ با یکاری تماس بگیرید');
        }
        $u = User::firstOrCreate(['mobile' => $mobile], ['name' => $d['name'] ?? '']);
        $u->assignRole('customer');
        $organization->members()->updateOrCreate(['user_id' => $u->id], ['role' => $d['role'] ?? 'member', 'monthly_limit' => $d['monthlyLimit'] ?? null, 'active' => true]);

        return $this->members($organization);
    }

    public function updateMember(Request $r, Organization $organization, OrganizationMember $member)
    {
        $this->authorize('manage', $organization);
        abort_unless($member->organization_id === $organization->id, 404);
        $d = $r->validate(['role' => 'sometimes|in:member,manager', 'monthlyLimit' => 'sometimes|nullable|integer|min:0', 'active' => 'sometimes|boolean']);
        if ($member->user_id === $r->user()->id && (($d['role'] ?? 'manager') !== 'manager' || ($d['active'] ?? true) === false)) {
            throw new ApiException('نمی‌توانی دسترسی مدیریت خودت را برداری');
        }
        $member->update(collect($d)->mapWithKeys(fn ($v, $k) => [$k === 'monthlyLimit' ? 'monthly_limit' : $k => $v])->all());

        return $this->members($organization);
    }

    /* ── مراکز هزینه ───────────────────────── */

    public function costCenters(Organization $organization)
    {
        $this->authorize('view', $organization);
        [$start] = Period::jalaliMonth();

        return response()->json(['data' => $organization->costCenters()->get()->map(fn (CostCenter $c) => [
            'id' => $c->id, 'title' => $c->title, 'active' => $c->active,
            'used' => (int) $organization->orders()->where('cost_center_id', $c->id)->whereNotIn('status', ['cancelled', 'expired'])
                ->where('created_at', '>=', $start)->get()->sum(fn ($o) => $o->total()),
        ])]);
    }

    public function saveCostCenter(Request $r, Organization $organization, ?CostCenter $costCenter = null)
    {
        $this->authorize('manage', $organization);
        $d = $r->validate(['title' => 'required|string|max:80', 'active' => 'boolean']);
        if ($costCenter?->exists) {
            abort_unless($costCenter->organization_id === $organization->id, 404);
            $costCenter->update($d);
        } else {
            $organization->costCenters()->create($d);
        }

        return $this->costCenters($organization);
    }

    /* ── گزارش مصرف ────────────────────────── */

    public function usage(Request $r, Organization $organization)
    {
        $this->authorize('view', $organization);
        $at = $r->query('month') ? Carbon::parse($r->query('month')) : Carbon::now();
        [$start, $end] = Period::jalaliMonth($at);
        $orders = $organization->orders()->with(['customer:id,name', 'costCenter:id,title'])
            ->whereNotIn('status', ['cancelled', 'expired', 'draft'])->whereBetween('created_at', [$start, $end])->get();

        return response()->json([
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'label' => Period::label($start)],
            'total' => (int) $orders->sum(fn ($o) => $o->total()),
            'orders' => $orders->count(),
            'byMember' => $orders->groupBy('customer_id')->map(fn ($g) => ['userId' => $g->first()->customer_id, 'name' => $g->first()->customer->name, 'orders' => $g->count(), 'total' => (int) $g->sum(fn ($o) => $o->total())])->values(),
            'byCostCenter' => $orders->groupBy(fn ($o) => $o->cost_center_id ?? 0)->map(fn ($g) => ['id' => $g->first()->cost_center_id, 'title' => $g->first()->costCenter?->title ?? 'بدون مرکز هزینه', 'orders' => $g->count(), 'total' => (int) $g->sum(fn ($o) => $o->total())])->values(),
            'byType' => $orders->groupBy('mission_type')->map(fn ($g, $k) => ['type' => $k, 'orders' => $g->count(), 'total' => (int) $g->sum(fn ($o) => $o->total())])->values(),
        ]);
    }

    public function orders(Request $r, Organization $organization)
    {
        $this->authorize('view', $organization);

        // شکل فشرده، نه OrderResource: مدیر سازمان باید ببیند «کدام عضو» سفارش داده، ولی
        // جزئیات پیک/کمیسیون مال او نیست
        $page = $organization->orders()->with(['customer:id,name', 'costCenter:id,title'])
            ->where('status', '!=', 'draft')->latest('id')->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($o) => [
                'id' => $o->id, 'code' => $o->code, 'type' => $o->mission_type, 'status' => $o->status,
                'createdAt' => $o->created_at?->toIso8601String(), 'total' => $o->total(),
                'member' => $o->customer?->name, 'costCenter' => $o->costCenter?->title,
            ]),
            'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /* ── فاکتور ────────────────────────────── */

    public function invoices(Organization $organization)
    {
        $this->authorize('view', $organization);

        return response()->json(['data' => $organization->invoices()->latest('period_start')->get()->map(fn (OrganizationInvoice $i) => [
            'id' => $i->id, 'number' => $i->number, 'periodStart' => $i->period_start->toDateString(), 'periodEnd' => $i->period_end->toDateString(),
            'label' => Period::label($i->period_start), 'orders' => $i->orders_count, 'subtotal' => (int) $i->subtotal, 'discount' => (int) $i->discount,
            'vat' => (int) $i->vat, 'total' => (int) $i->total, 'status' => $i->status, 'paidAt' => $i->paid_at?->toIso8601String(),
        ])]);
    }

    /** ریز فاکتور به CSV (با BOM تا اکسل فارسی را درست باز کند) */
    public function invoiceCsv(Organization $organization, OrganizationInvoice $invoice, InvoiceService $svc)
    {
        $this->authorize('view', $organization);
        abort_unless($invoice->organization_id === $organization->id, 404);
        $orders = $svc->ordersFor($organization, $invoice->period_start->startOfDay(), $invoice->period_end->endOfDay())->with(['customer:id,name', 'costCenter:id,title'])->get();

        return response()->streamDownload(function () use ($orders, $invoice) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['کد مأموریت', 'تاریخ', 'کاربر', 'مرکز هزینه', 'دسته', 'خدمات', 'مسافت', 'انتظار', 'کالا', 'تخفیف', 'جمع']);
            foreach ($orders as $o) {
                fputcsv($out, [$o->code, Jalalian::fromCarbon($o->finished_at)->format('Y/m/d'), $o->customer->name, $o->costCenter?->title, $o->mission_type,
                    $o->price_service, $o->price_distance, $o->price_waiting, $o->price_goods, $o->price_discount, $o->total()]);
            }
            fputcsv($out, []);
            fputcsv($out, ['جمع', '', '', '', '', '', '', '', '', $invoice->discount, $invoice->subtotal - $invoice->discount]);
            fputcsv($out, ['ارزش افزوده', '', '', '', '', '', '', '', '', '', $invoice->vat]);
            fputcsv($out, ['قابل پرداخت', '', '', '', '', '', '', '', '', '', $invoice->total]);
            fclose($out);
        }, "{$invoice->number}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** شارژ اعتبار سازمان پیش‌پرداخت از درگاه */
    public function topup(Request $r, Organization $organization, PaymentService $payments)
    {
        $this->authorize('manage', $organization);
        if (! $organization->isPrepaid()) {
            throw new ApiException('حساب پس‌پرداخت نیاز به شارژ ندارد');
        }
        $d = $r->validate(['amount' => 'required|integer|min:100000']);
        $res = $payments->start($r->user(), $this->wallets->for($organization), $d['amount'], 'corporate');

        return response()->json(['paymentUrl' => $res['url']]);
    }
}
