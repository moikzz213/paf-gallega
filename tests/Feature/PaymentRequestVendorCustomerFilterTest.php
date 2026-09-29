<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A vendor or customer is registered once per group company under the same name, so the Payment
 * Requests filters pick one record (by id) rather than a name that matches all of them.
 */
class PaymentRequestVendorCustomerFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $requester;

    private Vendor $gil;

    private Vendor $ggl;

    private Customer $custGil;

    private Customer $custGgl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = $this->user(User::ROLE_FINANCE);
        $this->requester = $this->user(User::ROLE_REQUESTER);

        // Same name, one record per company — as in the live master data.
        $this->gil = Vendor::create(['name' => '3PLOGISTICS', 'vendor_code' => 'VEN0008 - GIL', 'is_active' => true]);
        $this->ggl = Vendor::create(['name' => '3PLOGISTICS', 'vendor_code' => 'VEN0008 - GGL', 'is_active' => true]);
        $this->custGil = Customer::create(['name' => 'MAJEED ABDULLA', 'customer_code' => 'C0023 - GIL', 'is_active' => true]);
        $this->custGgl = Customer::create(['name' => 'MAJEED ABDULLA', 'customer_code' => 'C0023 - GGL', 'is_active' => true]);
    }

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

    private function postedInvoice(Vendor $vendor, ?Customer $customer = null, ?User $owner = null): Invoice
    {
        $inv = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_id' => $vendor->id, 'vendor_name' => $vendor->name,
            'invoice_no' => 'INV-'.uniqid(), 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 500, 'tax_amount' => 0, 'total_amount' => 500,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => ($owner ?? $this->requester)->id, 'submitted_at' => now(),
        ]);

        $inv->items()->create([
            'sort_order' => 0, 'customer_id' => $customer?->id,
            'currency' => 'AED', 'amount' => 500, 'tax_amount' => 0, 'total_amount' => 500,
        ]);

        return $inv;
    }

    private function eligibleIds(string $query): array
    {
        return collect($this->actingAs($this->finance)
            ->getJson('/api/payment-requests/eligible?'.$query)
            ->assertOk()
            ->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_eligible_vendor_filter_returns_only_that_companys_vendor(): void
    {
        $gilInvoice = $this->postedInvoice($this->gil);
        $gglInvoice = $this->postedInvoice($this->ggl);

        $this->assertSame([$gilInvoice->id], $this->eligibleIds("vendor_id={$this->gil->id}"));
        $this->assertSame([$gglInvoice->id], $this->eligibleIds("vendor_id={$this->ggl->id}"));
    }

    public function test_eligible_customer_filter_returns_only_that_companys_customer(): void
    {
        $gilInvoice = $this->postedInvoice($this->gil, $this->custGil);
        $gglInvoice = $this->postedInvoice($this->gil, $this->custGgl);
        $this->postedInvoice($this->gil); // no customer on its line

        $this->assertSame([$gilInvoice->id], $this->eligibleIds("customer_id={$this->custGil->id}"));
        $this->assertSame([$gglInvoice->id], $this->eligibleIds("customer_id={$this->custGgl->id}"));
    }

    public function test_eligible_record_filters_combine_with_other_filters(): void
    {
        $warehouse = $this->postedInvoice($this->gil, $this->custGil);
        $yard = $this->postedInvoice($this->gil, $this->custGil);
        $yard->update(['department' => 'Yard']);

        $this->assertSame(
            [$yard->id],
            $this->eligibleIds("vendor_id={$this->gil->id}&customer_id={$this->custGil->id}&department=Yard")
        );
        $this->assertNotContains($warehouse->id, $this->eligibleIds("vendor_id={$this->ggl->id}"));
    }

    public function test_eligible_name_filters_still_match_every_record_with_the_name(): void
    {
        $gilInvoice = $this->postedInvoice($this->gil, $this->custGil);
        $gglInvoice = $this->postedInvoice($this->ggl, $this->custGgl);
        $both = collect([$gilInvoice->id, $gglInvoice->id])->sort()->values()->all();

        $this->assertSame($both, $this->eligibleIds('vendor=3PLOG'));
        $this->assertSame($both, $this->eligibleIds('customer=MAJEED'));
    }

    public function test_eligible_rejects_a_non_numeric_record_filter(): void
    {
        $this->actingAs($this->finance)
            ->getJson('/api/payment-requests/eligible?vendor_id=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('vendor_id');

        $this->actingAs($this->finance)
            ->getJson('/api/payment-requests/eligible?customer_id=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');
    }

    public function test_eligible_stays_finance_only(): void
    {
        $this->actingAs($this->requester)
            ->getJson("/api/payment-requests/eligible?vendor_id={$this->gil->id}")
            ->assertForbidden();
    }

    public function test_list_vendor_filter_returns_only_that_companys_requests_within_visibility(): void
    {
        Mail::fake();
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        $approver = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $otherRequester = $this->user(User::ROLE_REQUESTER);
        $chain = [['approver_id' => $approver->id, 'label' => 'Manager', 'level' => 1, 'is_adhoc' => false]];
        $service = app(PaymentRequestService::class);

        $gilPr = $service->create([$this->postedInvoice($this->gil)->id], $this->finance, $chain);
        $gglPr = $service->create([$this->postedInvoice($this->ggl)->id], $this->finance, $chain);
        // Another requester's GIL request: the filter must not widen what a requester can see.
        $hiddenPr = $service->create([$this->postedInvoice($this->gil, null, $otherRequester)->id], $this->finance, $chain);

        $ids = fn (User $user, Vendor $vendor) => collect($this->actingAs($user)
            ->getJson("/api/payment-requests?vendor_id={$vendor->id}")
            ->assertOk()
            ->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$gilPr->id, $hiddenPr->id])->sort()->values()->all(), $ids($this->finance, $this->gil));
        $this->assertSame([$gglPr->id], $ids($this->finance, $this->ggl));
        $this->assertSame([$gilPr->id], $ids($this->requester, $this->gil));

        $this->actingAs($this->finance)
            ->getJson('/api/payment-requests?vendor_id=abc')
            ->assertUnprocessable();
    }
}
