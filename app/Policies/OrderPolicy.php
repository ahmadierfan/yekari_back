<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /** دیدن: صاحب سفارش، پیکِ همین سفارش، مدیر سازمانِ پرداخت‌کننده، یا کارمند با orders.view */
    public function view(User $user, Order $order): bool
    {
        return $order->customer_id === $user->id
            || ($order->courier_id && $order->courier_id === $user->id)
            || $user->can('orders.view')
            || ($order->organization_id && $user->memberships()->where('organization_id', $order->organization_id)->where('role', 'manager')->exists());
    }

    public function customerAct(User $user, Order $order): bool
    {
        return $order->customer_id === $user->id;
    }

    public function courierAct(User $user, Order $order): bool
    {
        return $order->courier_id !== null && $order->courier_id === $user->id;
    }

    /** چت فقط بین دو طرف مأموریت؛ کارمند فقط می‌خواند */
    public function chat(User $user, Order $order): bool
    {
        return $this->customerAct($user, $order) || $this->courierAct($user, $order);
    }

    public function manage(User $user): bool
    {
        return $user->can('orders.manage');
    }
}
