<?php

namespace Tests\Feature;

use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    /** اپ مشتری بعد از ثبت، مستقیم صفحهٔ تیکت را با همین پاسخ می‌سازد؛ بدون وضعیت، صفحه می‌شکست */
    public function test_new_ticket_response_has_status(): void
    {
        $customer = $this->user('09121234567', ['customer']);
        $this->postJson('/api/v1/customer/tickets', ['topic' => 'payment', 'subject' => 'انعام دوبار کم شده'], $this->tokenFor($customer, 'customer'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'open');
    }
}
