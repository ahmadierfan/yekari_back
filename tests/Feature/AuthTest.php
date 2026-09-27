<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_customer_signs_up_with_otp_and_gets_app_scoped_token(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['mobile' => '۰۹۱۲۱۲۳۴۵۶۷', 'app' => 'customer'])->assertOk();
        $code = $this->sms['09121234567'];

        $this->postJson('/api/v1/auth/otp/verify', ['mobile' => '09121234567', 'code' => '00000', 'app' => 'customer'])->assertStatus(422);
        $res = $this->postJson('/api/v1/auth/otp/verify', ['mobile' => '9121234567', 'code' => $code, 'app' => 'customer'])
            ->assertOk()->assertJsonPath('isNew', true)->assertJsonPath('user.roles.0', 'customer');

        $h = ['Authorization' => 'Bearer '.$res['token'], 'Accept' => 'application/json'];
        $this->getJson('/api/v1/customer/addresses', $h)->assertOk();
        // توکن اپ مشتری روی پنل و اپ پیک کار نمی‌کند
        $this->getJson('/api/v1/admin/dashboard', $h)->assertForbidden();
        $this->getJson('/api/v1/courier/profile', $h)->assertForbidden();
    }

    public function test_otp_code_is_single_use_and_resend_is_throttled(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09121234567', 'app' => 'customer'])->assertOk();
        $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09121234567', 'app' => 'customer'])->assertStatus(429);
        $code = $this->sms['09121234567'];
        $this->postJson('/api/v1/auth/otp/verify', ['mobile' => '09121234567', 'code' => $code, 'app' => 'customer'])->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', ['mobile' => '09121234567', 'code' => $code, 'app' => 'customer'])->assertStatus(422);
    }

    public function test_admin_panel_rejects_non_staff_and_accepts_staff_password_login(): void
    {
        $this->user('09121234567', ['customer']);
        $this->postJson('/api/v1/auth/login', ['login' => '09121234567', 'password' => 'password123', 'app' => 'admin'])->assertForbidden();

        $staff = $this->user('09122220000', ['ops']);
        $staff->update(['email' => 'ops@yekari.ir']);
        $this->postJson('/api/v1/auth/login', ['login' => 'ops@yekari.ir', 'password' => 'wrong', 'app' => 'admin'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['login' => 'ops@yekari.ir', 'password' => 'password123', 'app' => 'admin'])
            ->assertOk()->assertJsonPath('user.permissions', fn ($p) => in_array('orders.manage', $p) && ! in_array('finance.payouts', $p));
        $this->assertDatabaseHas('activity_logs', ['kind' => 'login', 'actor_id' => $staff->id]);
    }

    public function test_admin_otp_does_not_create_accounts(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09129999999', 'app' => 'admin'])->assertOk();
        $this->assertArrayNotHasKey('09129999999', $this->sms);
        $this->assertDatabaseMissing('users', ['mobile' => '09129999999']);
    }

    public function test_blocked_user_cannot_log_in_and_block_revokes_tokens(): void
    {
        $u = $this->user('09121234567', ['customer']);
        $h = $this->tokenFor($u, 'customer');
        $admin = $this->user('09121110000', ['ops']);
        $this->postJson("/api/v1/admin/users/{$u->id}/block", [], $this->tokenFor($admin, 'admin'))->assertOk();
        $this->assertSame(0, $u->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['login' => '09121234567', 'password' => 'password123', 'app' => 'customer'])->assertForbidden();
    }

    public function test_password_reset_with_otp(): void
    {
        $u = $this->user('09121234567', ['customer']);
        $this->postJson('/api/v1/auth/password/forgot', ['mobile' => '09121234567'])->assertOk();
        $this->postJson('/api/v1/auth/password/reset', ['mobile' => '09121234567', 'code' => $this->sms['09121234567'], 'password' => 'newpass123'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['login' => '09121234567', 'password' => 'newpass123', 'app' => 'customer'])->assertOk();
    }

    public function test_courier_signup_creates_pending_profile_and_cannot_go_online(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09351234567', 'app' => 'courier']);
        $res = $this->postJson('/api/v1/auth/otp/verify', ['mobile' => '09351234567', 'code' => $this->sms['09351234567'], 'app' => 'courier'])->assertOk();
        $h = ['Authorization' => 'Bearer '.$res['token'], 'Accept' => 'application/json'];
        $this->getJson('/api/v1/courier/profile', $h)->assertOk()->assertJsonPath('profile.state', 'pending')->assertJsonPath('docs.id', 'missing');
        $this->postJson('/api/v1/courier/online', ['online' => true], $h)->assertForbidden();
    }
}
