<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Payment Requests list can be narrowed by request status, alongside vendor and department.
 * The server accepted `status` before the filter existed in the UI; these lock the behaviour the
 * page now depends on, including the comma-separated form for several statuses at once.
 */
class PaymentRequestStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::create([
            'name' => 'Fin', 'email' => 'fin@t.local', 'password' => 'password',
            'role' => User::ROLE_FINANCE, 'is_active' => true,
        ]);
    }

    private function request(string $status, string $department = 'Warehouse'): PaymentRequest
    {
        $pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $this->finance->id,
            'status' => $status,
            'total_amount' => 500,
            'sent_at' => now(),
        ]);

        Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Vendor', 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 500, 'tax_amount' => 0, 'total_amount' => 500,
            'business_unit' => 'GIL', 'department' => $department, 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_IN_APPROVAL,
            'submitted_by' => $this->finance->id, 'submitted_at' => now(),
            'payment_request_id' => $pr->id,
        ]);

        return $pr;
    }

    public function test_a_single_status_narrows_the_list(): void
    {
        $inApproval = $this->request(PaymentRequest::STATUS_IN_APPROVAL);
        $this->request(PaymentRequest::STATUS_PAID);
        $this->request(PaymentRequest::STATUS_WITHDRAWN);

        $res = $this->actingAs($this->finance)
            ->getJson('/api/payment-requests?status='.PaymentRequest::STATUS_IN_APPROVAL)
            ->assertOk();

        $this->assertSame([$inApproval->id], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame(1, $res->json('total'));
    }

    public function test_several_statuses_can_be_combined(): void
    {
        $approved = $this->request(PaymentRequest::STATUS_APPROVED);
        $paid = $this->request(PaymentRequest::STATUS_PAID);
        $this->request(PaymentRequest::STATUS_REJECTED);

        $res = $this->actingAs($this->finance)
            ->getJson('/api/payment-requests?status=approved,paid')
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$approved->id, $paid->id],
            collect($res->json('data'))->pluck('id')->all(),
        );
    }

    public function test_no_status_returns_everything_and_an_unknown_one_returns_nothing(): void
    {
        $this->request(PaymentRequest::STATUS_IN_APPROVAL);
        $this->request(PaymentRequest::STATUS_PAID);

        $this->actingAs($this->finance)->getJson('/api/payment-requests')->assertOk()->assertJsonPath('total', 2);
        $this->actingAs($this->finance)->getJson('/api/payment-requests?status=nonsense')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_status_combines_with_the_other_filters(): void
    {
        $this->request(PaymentRequest::STATUS_APPROVED, 'Warehouse');
        $this->request(PaymentRequest::STATUS_APPROVED, 'Yard');
        $this->request(PaymentRequest::STATUS_PAID, 'Yard');

        $this->actingAs($this->finance)
            ->getJson('/api/payment-requests?status=approved&department=Yard')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', PaymentRequest::STATUS_APPROVED);
    }

    /** The page builds its options from this list, so it has to cover every status. */
    public function test_meta_exposes_every_request_status_for_the_filter(): void
    {
        $this->actingAs($this->finance)
            ->getJson('/api/meta')
            ->assertOk()
            ->assertJsonPath('pr_statuses', PaymentRequest::STATUSES);
    }
}
