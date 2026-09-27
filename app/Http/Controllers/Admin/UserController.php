<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CourierDocument;
use App\Models\CourierProfile;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit;
use App\Services\Notifier;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** مشتری‌ها و انجام‌دهنده‌ها از دید پنل */
class UserController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    public function users(Request $r)
    {
        $q = User::role('customer')->with(['wallet', 'memberships'])
            ->withCount(['orders as missions' => fn ($q) => $q->where('status', 'completed')])
            ->when($r->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('mobile', 'like', "%$s%")))
            ->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate(min(100, $r->integer('perPage', 25)));

        $q->getCollection()->transform(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'phone' => $u->mobile, 'email' => $u->email, 'status' => $u->status,
            'missions' => $u->missions,
            'spent' => (int) Order::where('customer_id', $u->id)->where('status', 'completed')->get()->sum(fn ($o) => $o->total()),
            'wallet' => (int) ($u->wallet->balance ?? 0),
            'joinedAt' => $u->created_at->toIso8601String(), 'lastSeen' => $u->last_seen_at?->toIso8601String(),
            'contractId' => $u->memberships->firstWhere('active', true)?->organization_id,
        ]);

        return $q;
    }

    public function toggleBlock(Request $r, User $user)
    {
        abort_if($user->id === $r->user()->id || $user->hasRole('super-admin'), 422, 'این کاربر را نمی‌شود مسدود کرد');
        $user->update(['status' => $user->isBlocked() ? 'active' : 'blocked']);
        if ($user->isBlocked()) {
            $user->tokens()->delete(); // خروج فوری از همهٔ دستگاه‌ها
            $user->courierProfile?->update(['online' => false]);
        }
        Audit::log('block', "کاربر {$user->name} ".($user->isBlocked() ? 'مسدود شد' : 'رفع مسدودیت شد'), $user);

        return response()->json(['status' => $user->status]);
    }

    public function couriers(Request $r)
    {
        $q = CourierProfile::with(['user', 'zone'])
            ->when($r->query('state'), fn ($q, $s) => $q->where('state', $s))
            ->when($r->query('q'), fn ($q, $s) => $q->whereHas('user', fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('mobile', 'like', "%$s%")))
            ->latest('created_at')->paginate(min(100, $r->integer('perPage', 25)));

        $q->getCollection()->transform(fn (CourierProfile $p) => $this->courierRow($p));

        return $q;
    }

    private function courierRow(CourierProfile $p): array
    {
        return [
            'id' => $p->user_id, 'name' => $p->user->name, 'phone' => $p->user->mobile, 'state' => $p->state,
            'vehicle' => $p->vehicle, 'plate' => $p->plate, 'zone' => $p->zone?->title, 'rating' => (float) $p->rating,
            'missions' => $p->missions_count, 'acceptRate' => $p->acceptRate(), 'cancelRate' => $p->cancelRate(),
            'balance' => (int) ($this->wallets->for($p->user)->balance), 'online' => $p->online,
            'joinedAt' => $p->created_at->toIso8601String(), 'userStatus' => $p->user->status,
        ];
    }

    public function courier(User $user)
    {
        $p = CourierProfile::with(['user', 'zone'])->findOrFail($user->id);

        return response()->json($this->courierRow($p) + [
            'documents' => CourierDocument::where('user_id', $user->id)->get()->map(fn ($d) => [
                'id' => $d->id, 'key' => $d->key, 'status' => $d->status, 'rejectReason' => $d->reject_reason,
                'reviewedAt' => $d->reviewed_at?->toIso8601String(), 'url' => route('admin.documents.file', $d->id),
            ]),
        ]);
    }

    /** فایل مدرک روی دیسک خصوصی است؛ فقط از این مسیر و با couriers.view */
    public function documentFile(CourierDocument $document)
    {
        return Storage::disk('local')->response($document->path);
    }

    public function reviewDocument(Request $r, CourierDocument $document)
    {
        $d = $r->validate(['status' => 'required|in:verified,rejected', 'reason' => 'required_if:status,rejected|nullable|string|max:255']);
        $document->update(['status' => $d['status'], 'reject_reason' => $d['reason'] ?? null, 'reviewed_by' => $r->user()->id, 'reviewed_at' => now()]);
        $user = $document->user;
        $p = $user->courierProfile;
        // همهٔ مدارک که تأیید شد، پیکِ در انتظار خودکار فعال می‌شود
        if ($p && $p->state === 'pending' && $p->isVerified()) {
            $p->update(['state' => 'active']);
            Notifier::send($user->id, 'courier', 'مدارکت تأیید شد', 'حالا می‌توانی آنلاین شوی.', 'check-circle', 'ok', '/courier');
        } elseif ($d['status'] === 'rejected') {
            Notifier::send($user->id, 'courier', 'یک مدرک رد شد', $d['reason'], 'alert-triangle', 'danger', '/courier/verify');
        }
        Audit::log('update', "مدرک «{$document->key}» پیک {$user->name} ".($d['status'] === 'verified' ? 'تأیید' : 'رد').' شد', $document);

        return $this->courier($user);
    }

    public function setCourierState(Request $r, User $user)
    {
        $d = $r->validate(['state' => 'required|in:pending,active,suspended', 'minPayout' => 'nullable|integer|min:0']);
        $p = CourierProfile::findOrFail($user->id);
        $p->state = $d['state'];
        if ($d['state'] !== 'active') {
            $p->online = false; // تعلیق‌شده نباید مأموریت بگیرد
        }
        if (isset($d['minPayout'])) {
            $p->min_payout = $d['minPayout'];
        }
        $p->save();
        $label = ['active' => 'تأیید شد', 'suspended' => 'تعلیق شد', 'pending' => 'به حالت بررسی برگشت'][$d['state']];
        Audit::log('update', "انجام‌دهنده {$user->name} $label", $user);

        return $this->courier($user);
    }
}
