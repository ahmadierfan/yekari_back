<?php

namespace App\Http\Resources;

use App\Domain\Permissions;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'status' => $this->status,
            'prefs' => $this->prefs ?? ['push' => true, 'sms' => true, 'offers' => false],
            'onboarded' => (bool) $this->onboarded_at,
            'hasPassword' => (bool) $this->password,
            'memberSince' => $this->created_at?->toIso8601String(),
            // «مأموریت‌های من» در پروفایل مشتری — فقط سفارش‌های تکمیل‌شده
            'missions' => $this->orders()->where('status', 'completed')->count(),
            'roles' => $this->getRoleNames(),
            'permissions' => $this->when($this->isStaff(), fn () => $this->hasRole('super-admin')
                ? Permissions::all()
                : $this->getAllPermissions()->pluck('name')),
        ];
    }
}
