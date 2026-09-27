<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Withdrawal;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    public function test_each_admin_role_sees_only_its_sections(): void
    {
        $support = $this->tokenFor($this->user('09124440000', ['support']), 'admin');
        $finance = $this->tokenFor($this->user('09123330000', ['finance']), 'admin');
        $ops = $this->tokenFor($this->user('09122220000', ['ops']), 'admin');

        $this->getJson('/api/v1/admin/orders', $support)->assertOk();
        $this->getJson('/api/v1/admin/tickets', $support)->assertOk();
        $this->getJson('/api/v1/admin/withdrawals', $support)->assertForbidden();
        $this->getJson('/api/v1/admin/pricing', $support)->assertForbidden();
        $this->getJson('/api/v1/admin/roles', $support)->assertForbidden();

        $this->getJson('/api/v1/admin/withdrawals', $finance)->assertOk();
        $this->patchJson('/api/v1/admin/pricing/settings', ['commission' => 20], $finance)->assertOk()->assertJsonPath('commission', 20);
        $this->getJson('/api/v1/admin/users', $finance)->assertForbidden();

        $this->getJson('/api/v1/admin/users', $ops)->assertOk();
        $this->getJson('/api/v1/admin/pricing', $ops)->assertForbidden();
        $this->getJson('/api/v1/admin/dashboard', $ops)->assertOk()->assertJsonPath('pendingPayouts', null);
    }

    public function test_super_admin_edits_role_matrix_and_it_takes_effect(): void
    {
        $super = $this->tokenFor($this->user('09121110000', ['super-admin']), 'admin');
        $support = $this->tokenFor($this->user('09124440000', ['support']), 'admin');
        $this->getJson('/api/v1/admin/logs', $super)->assertOk();

        $this->getJson('/api/v1/admin/content', $support)->assertForbidden();
        $this->putJson('/api/v1/admin/roles/support', ['groups' => ['tickets', 'content']], $super)->assertOk();
        $this->getJson('/api/v1/admin/content', $support)->assertOk();
        $this->putJson('/api/v1/admin/roles/super-admin', ['groups' => []], $super)->assertStatus(422);
        $this->assertDatabaseHas('activity_logs', ['kind' => 'update']);
    }

    public function test_only_super_admin_can_grant_super_admin(): void
    {
        $opsUser = $this->user('09122220000', ['ops']);
        Role::findByName('ops')->givePermissionTo('roles.manage');
        $this->postJson('/api/v1/admin/staff', ['name' => 'x', 'mobile' => '09125550000', 'role' => 'super-admin'], $this->tokenFor($opsUser, 'admin'))->assertForbidden();
        $this->postJson('/api/v1/admin/staff', ['name' => 'x', 'mobile' => '09125550000', 'role' => 'support'], $this->tokenFor($opsUser, 'admin'))->assertCreated();
    }

    public function test_payout_rejection_refunds_courier(): void
    {
        $courier = $this->courier();
        $this->fund($courier, 500000);
        $ch = $this->tokenFor($courier, 'courier');
        $card = $this->postJson('/api/v1/courier/cards', ['pan' => '6104337812349825', 'owner' => 'مهدی'], $ch)->assertCreated();
        $this->postJson('/api/v1/courier/cards', ['pan' => '6104337812349826', 'owner' => 'مهدی'], $ch)->assertStatus(422);
        $this->postJson('/api/v1/courier/payouts', ['amount' => 300000, 'cardId' => $card['data']['id']], $ch)->assertCreated();
        $this->assertSame(200000, (int) $courier->wallet->fresh()->balance);

        $finance = $this->tokenFor($this->user('09123330000', ['finance']), 'admin');
        $w = Withdrawal::first();
        $this->postJson("/api/v1/admin/withdrawals/{$w->id}/resolve", ['ok' => false, 'reason' => 'کارت به نام شخص نیست'], $finance)->assertOk();
        $this->postJson("/api/v1/admin/withdrawals/{$w->id}/resolve", ['ok' => true], $finance)->assertStatus(409);
        $this->assertSame(500000, (int) $courier->wallet->fresh()->balance);
    }

    public function test_corporate_manager_manages_only_own_org_and_members_pay_with_it(): void
    {
        $org = Organization::create(['name' => 'کلینیک دی', 'status' => 'active', 'billing_model' => 'postpaid', 'monthly_ceiling' => 1000000, 'discount_percent' => 10]);
        $other = Organization::create(['name' => 'دیگری', 'status' => 'active', 'billing_model' => 'prepaid']);
        $manager = $this->user('09123456789', ['customer']);
        $org->members()->create(['user_id' => $manager->id, 'role' => 'manager']);
        $mh = $this->tokenFor($manager, 'corporate');

        $this->getJson("/api/v1/corporate/orgs/{$other->id}/members", $mh)->assertForbidden();
        $this->postJson("/api/v1/corporate/orgs/{$org->id}/members", ['mobile' => '09121234567', 'monthlyLimit' => 200000], $mh)->assertOk();
        $cc = $this->postJson("/api/v1/corporate/orgs/{$org->id}/cost-centers", ['title' => 'اداری'], $mh)->assertOk()['data'][0]['id'];

        // عضو ساده به پنل سازمانی راه ندارد
        $member = User::where('mobile', '09121234567')->first();
        $this->getJson('/api/v1/corporate/orgs', $this->tokenFor($member, 'corporate'))->assertForbidden();

        $a = $member->addresses()->create(['title' => 'a', 'detail' => 'a', 'lat' => 35.78, 'lng' => 51.37]);
        $ch = $this->tokenFor($member, 'customer');
        $this->getJson('/api/v1/customer/membership', $ch)->assertOk()->assertJsonPath('data.company', 'کلینیک دی');
        $body = ['type' => 'report', 'pickup' => $a->id, 'description' => 'عکس از ساختمان', 'payMethod' => 'corporate'];
        $this->postJson('/api/v1/customer/orders', $body, $ch)->assertStatus(422); // مرکز هزینه لازم است
        $res = $this->postJson('/api/v1/customer/orders', $body + ['costCenterId' => $cc], $ch)->assertCreated();
        $this->assertSame('invoiced', $res['data']['paymentStatus']);
        // تخفیف قراردادی ۱۰٪
        $this->assertSame(8000, $res['data']['price']['discount']);
        // سقف فردی ۲۰۰ هزار: سفارش دوم (۶۷ هزار) هنوز جا دارد، سوم و چهارم نه
        $this->postJson('/api/v1/customer/orders', $body + ['costCenterId' => $cc], $ch)->assertCreated();
        $this->postJson('/api/v1/customer/orders', $body + ['costCenterId' => $cc], $ch)->assertStatus(422);

        $this->getJson("/api/v1/corporate/orgs/{$org->id}/usage", $mh)->assertOk()->assertJsonPath('orders', 2);
    }

    public function test_chat_only_between_the_two_sides(): void
    {
        $customer = $this->user('09121234567', ['customer']);
        $this->fund($customer, 1000000);
        $courier = $this->courier();
        $a = $customer->addresses()->create(['title' => 'a', 'detail' => 'a', 'lat' => 35.78, 'lng' => 51.37]);
        $id = $this->postJson('/api/v1/customer/orders', ['type' => 'report', 'pickup' => $a->id, 'description' => 'عکس از ساختمان', 'payMethod' => 'wallet'], $this->tokenFor($customer, 'customer'))['data']['id'];
        $ch = $this->tokenFor($courier, 'courier');
        $offer = $this->getJson('/api/v1/courier/current', $ch)['offer'];
        $this->postJson("/api/v1/courier/offers/{$offer['id']}/accept", [], $ch)->assertOk();

        $this->postJson("/api/v1/customer/orders/$id/messages", ['kind' => 'text', 'text' => 'سلام'], $this->tokenFor($customer, 'customer'))->assertCreated()->assertJsonPath('data.from', 'customer');
        $this->postJson("/api/v1/courier/missions/$id/messages", ['kind' => 'text', 'text' => 'در راهم'], $ch)->assertCreated()->assertJsonPath('data.from', 'courier');
        $stranger = $this->courier('09350000000');
        $this->postJson("/api/v1/courier/missions/$id/messages", ['kind' => 'text', 'text' => 'x'], $this->tokenFor($stranger, 'courier'))->assertForbidden();
        $this->getJson("/api/v1/customer/orders/$id/messages", $this->tokenFor($customer, 'customer'))->assertOk()->assertJsonCount(2, 'data');
    }
}
