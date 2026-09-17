<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Approval thresholds are base-currency (AED) amounts. A request raised in another currency must be
 * converted before it is measured against them: USD 36,000 is worth roughly AED 132,000 and has to
 * reach the AED 50,000 level, even though its face value of 36,000 sits below that number.
 */
class CurrencyConvertedApprovalRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $extra = []): User
    {
        return User::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.uniqid().'@t.local',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
            ...$extra,
        ]);
    }

    /** The create_currencies_table migration already seeds the configured codes, so set, don't add. */
    private function rate(string $code, ?float $rate): Currency
    {
        $currency = Currency::firstOrNew(['name' => $code]);
        $currency->fill(['exchange_rate' => $rate, 'is_active' => true])->save();

        return $currency;
    }

    private function levels(): void
    {
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        ApprovalLevel::create(['level' => 2, 'name' => 'Head of Finance', 'min_amount' => 50000, 'is_active' => true]);
    }

    private function postedInvoice(User $owner, float $total, string $currency = 'AED'): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Vendor', 'invoice_no' => 'INV-'.uniqid(), 'invoice_date' => now()->toDateString(),
            'currency' => $currency, 'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office', 'payment_method' => 'bank_transfer',
            'priority' => 'normal', 'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $owner->id, 'submitted_at' => now(),
        ]);
    }

    public function test_foreign_currency_total_is_converted_before_it_is_measured_against_thresholds(): void
    {
        $this->levels();
        $this->rate('AED', 1);
        $this->rate('USD', 3.6725);

        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $kareem = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);
        $inv = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 36000, 'USD');

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$inv->id],
            'approvers' => [1 => $a1->id, 2 => $kareem->id],
        ])->assertCreated();

        // USD 36,000 = AED 132,210 — over the AED 50,000 level, so Kareem is on the chain.
        $pr = PaymentRequest::first();
        $this->assertSame([$a1->id, $kareem->id], $pr->approvals->pluck('approver_id')->all());
    }

    public function test_a_foreign_amount_below_the_threshold_once_converted_still_skips_the_upper_level(): void
    {
        $this->levels();
        $this->rate('USD', 3.6725);

        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $kareem = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);
        // USD 10,000 = AED 36,725, under the AED 50,000 level.
        $inv = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 10000, 'USD');

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$inv->id],
            'approvers' => [1 => $a1->id, 2 => $kareem->id],
        ])->assertCreated();

        $this->assertSame([$a1->id], PaymentRequest::first()->approvals->pluck('approver_id')->all());
    }

    public function test_base_currency_routing_is_unchanged(): void
    {
        $this->levels();
        $this->rate('AED', 1);

        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $kareem = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);
        $inv = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 60000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$inv->id],
            'approvers' => [1 => $a1->id, 2 => $kareem->id],
        ])->assertCreated();

        $this->assertSame([$a1->id, $kareem->id], PaymentRequest::first()->approvals->pluck('approver_id')->all());
    }

    public function test_a_currency_with_no_rate_is_refused_rather_than_routed_one_to_one(): void
    {
        $this->levels();
        $this->rate('EUR', null);

        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $inv = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 36000, 'EUR');

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$inv->id],
            'approvers' => [1 => $a1->id],
        ])->assertStatus(422)->assertJsonValidationErrors('currency');

        $this->assertSame(0, PaymentRequest::count());
        $this->assertSame(Invoice::PAY_NOT_INITIATED, $inv->refresh()->payment_status);
    }

    public function test_admin_can_set_an_exchange_rate_from_master_data(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $usd = $this->rate('USD', null);

        $this->actingAs($admin)->putJson("/api/master-data/currencies/{$usd->id}", [
            'name' => 'USD',
            'exchange_rate' => 3.6725,
            'is_active' => true,
        ])->assertOk();

        $this->assertSame(3.6725, (float) $usd->refresh()->exchange_rate);

        $this->actingAs($admin)->putJson("/api/master-data/currencies/{$usd->id}", [
            'name' => 'USD',
            'exchange_rate' => 0,
            'is_active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('exchange_rate');
    }
}
