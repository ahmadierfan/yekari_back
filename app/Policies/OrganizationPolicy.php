<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/** نقش داخل سازمان روی عضویت است؛ کارمند یکاری با corporate.* از بیرون مدیریت می‌کند */
class OrganizationPolicy
{
    public function view(User $user, Organization $org): bool
    {
        return $user->can('corporate.view') || $this->isManager($user, $org);
    }

    public function manage(User $user, Organization $org): bool
    {
        return $this->isManager($user, $org) && $org->status === 'active';
    }

    private function isManager(User $user, Organization $org): bool
    {
        return $user->memberships()->where('organization_id', $org->id)->where('role', 'manager')->where('active', true)->exists();
    }
}
