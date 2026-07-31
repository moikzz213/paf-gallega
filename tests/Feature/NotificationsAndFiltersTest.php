<?php

namespace Tests\Feature;

use App\Mail\InvoiceQueryRaised;
use App\Mail\PaymentRequestApproved;
use App\Mail\PaymentRequestReminder;
use App\Mail\PaymentRequestSubmitted;
use App\Models\ApprovalLevel;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationsAndFiltersTest extends TestCase
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

    private function postedInvoice(User $owner, array $overrides = [], array $items = []): Invoice
    {
        $inv = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Vendor', 'invoice_no' => 'INV-'.uniqid(), 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 500, 'tax_amount' => 0, 'total_amount' => 500,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $owner->id, 'submitted_at' => now(),
            ...$overrides,
        ]);

        foreach ($items ?: [['currency' => 'AED', 'amount' => 500]] as $i => $item) {
            $inv->items()->create([
                'sort_order' => $i,
                'job_no' => $item['job_no'] ?? null,
                'customer_id' => $item['customer_id'] ?? null,
                'description' => $item['description'] ?? null,
                'currency' => $item['currency'] ?? 'AED',
                'amount' => $item['amount'] ?? 500,
                'tax_amount' => 0,
                'total_amount' => $item['amount'] ?? 500,
            ]);
        }

        return $inv;
    }

    public function test_raising_a_query_emails_the_submitter(): void
    {
        Mail::fake();
        $finance = $this->user(User::ROLE_FINANCE);
        $requester = $this->user(User::ROLE_REQUESTER);
        $inv = $this->postedInvoice($requester, ['status' => Invoice::STATUS_SUBMITTED]);

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$inv->id}/query", ['finance_remarks' => 'PO number missing'])
            ->assertOk();

        Mail::assertSent(InvoiceQueryRaised::class, fn ($mail) => $mail->hasTo($requester->email));
    }

    public function test_full_approval_emails_the_requestors(): void
    {
        Mail::fake();
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        $finance = $this->user(User::ROLE_FINANCE);
        $requester = $this->user(User::ROLE_REQUESTER);
        $approver = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $inv = $this->postedInvoice($requester);

        $service = app(PaymentRequestService::class);
        $pr = $service->create([$inv->id], $finance, [
            ['approver_id' => $approver->id, 'label' => 'Manager', 'level' => 1, 'is_adhoc' => false],
        ]);

        $service->approve($pr, $approver);

        $this->assertSame(PaymentRequest::STATUS_APPROVED, $pr->refresh()->status);
        Mail::assertSent(PaymentRequestApproved::class, fn ($m) => $m->hasTo($requester->email));
        Mail::assertSent(PaymentRequestApproved::class, fn ($m) => $m->hasTo($finance->email));
    }

    public function test_no_approval_email_until_final_stage(): void
    {
        Mail::fake();
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        ApprovalLevel::create(['level' => 2, 'name' => 'Director', 'min_amount' => 0, 'is_active' => true]);
        $finance = $this->user(User::ROLE_FINANCE);
        $requester = $this->user(User::ROLE_REQUESTER);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $a2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);
        $inv = $this->postedInvoice($requester);

        $service = app(PaymentRequestService::class);
        $pr = $service->create([$inv->id], $finance, [
            ['approver_id' => $a1->id, 'label' => 'Manager', 'level' => 1, 'is_adhoc' => false],
            ['approver_id' => $a2->id, 'label' => 'Director', 'level' => 2, 'is_adhoc' => false],
        ]);

        $service->approve($pr, $a1); // first of two stages
        Mail::assertNotSent(PaymentRequestApproved::class);

        $service->approve($pr->refresh(), $a2); // final stage
        Mail::assertSent(PaymentRequestApproved::class);
    }

    public function test_eligible_filters_by_job_customer_vendor(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $requester = $this->user(User::ROLE_REQUESTER);
        $acme = Customer::create(['name' => 'Acme Corp', 'is_active' => true]);

        $match = $this->postedInvoice($requester, ['vendor_name' => 'Gulf Traders', 'department' => 'Yard', 'currency' => 'USD'],
            [['job_no' => 'JOB-777', 'customer_id' => $acme->id, 'currency' => 'USD', 'amount' => 500]]);
        $other = $this->postedInvoice($requester, ['vendor_name' => 'Other LLC'],
            [['job_no' => 'JOB-001', 'currency' => 'AED', 'amount' => 300]]);

        $this->actingAs($finance);

        $byJob = $this->getJson('/api/payment-requests/eligible?job_no=777')->assertOk();
        $this->assertSame([$match->id], collect($byJob->json('data'))->pluck('id')->all());

        $byCustomer = $this->getJson('/api/payment-requests/eligible?customer=Acme')->assertOk();
        $this->assertSame([$match->id], collect($byCustomer->json('data'))->pluck('id')->all());

        $byVendor = $this->getJson('/api/payment-requests/eligible?vendor=Gulf')->assertOk();
        $this->assertSame([$match->id], collect($byVendor->json('data'))->pluck('id')->all());

        $byCurrency = $this->getJson('/api/payment-requests/eligible?currency=USD')->assertOk();
        $this->assertSame([$match->id], collect($byCurrency->json('data'))->pluck('id')->all());

        $this->assertCount(2, $this->getJson('/api/payment-requests/eligible')->json('data'));
    }

    public function test_approval_views_and_emails_include_invoice_line_details_and_submitter(): void
    {
        Mail::fake();
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        $finance = $this->user(User::ROLE_FINANCE);
        $requester = $this->user(User::ROLE_REQUESTER, ['name' => 'Invoice Submitter']);
        $approver = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $customer = Customer::create(['name' => 'Blue Ocean Customer', 'is_active' => true]);
        $invoice = $this->postedInvoice($requester, ['vendor_name' => 'Gulf Vendor'], [[
            'job_no' => 'JOB-2048',
            'customer_id' => $customer->id,
            'description' => 'Warehouse handling service',
            'currency' => 'USD',
            'amount' => 500,
        ]]);

        $pr = app(PaymentRequestService::class)->create([$invoice->id], $finance, [[
            'approver_id' => $approver->id,
            'label' => 'Manager',
            'level' => 1,
            'is_adhoc' => false,
        ]]);

        $this->actingAs($approver)
            ->getJson("/api/payment-requests/{$pr->id}")
            ->assertOk()
            ->assertJsonPath('invoices.0.submitter.name', 'Invoice Submitter')
            ->assertJsonPath('invoices.0.items.0.job_no', 'JOB-2048')
            ->assertJsonPath('invoices.0.items.0.description', 'Warehouse handling service')
            ->assertJsonPath('invoices.0.items.0.currency', 'USD')
            ->assertJsonPath('invoices.0.items.0.customer.name', 'Blue Ocean Customer');

        $this->getJson('/api/payment-requests/pending')
            ->assertOk()
            ->assertJsonPath('data.0.invoices.0.submitter.name', 'Invoice Submitter')
            ->assertJsonPath('data.0.invoices.0.items.0.job_no', 'JOB-2048')
            ->assertJsonPath('data.0.invoices.0.items.0.customer.name', 'Blue Ocean Customer');

        $token = $pr->currentApproval()->view_token;
        $this->get(route('payment-request.public', ['id' => $pr->id, 'token' => $token]))
            ->assertOk()
            ->assertSeeText('Invoice Submitted By')
            ->assertSeeText('Invoice Submitter')
            ->assertSeeText('JOB-2048')
            ->assertSeeText('Blue Ocean Customer')
            ->assertSeeText('Warehouse handling service')
            ->assertSeeText('USD 500.00')
            ->assertDontSeeText('Currency');

        foreach ([new PaymentRequestSubmitted($pr), new PaymentRequestReminder($pr)] as $mail) {
            $html = $mail->render();
            $this->assertStringContainsString('Invoice submitted by:', $html);
            $this->assertStringContainsString('Invoice Submitter', $html);
            $this->assertStringContainsString('JOB-2048', $html);
            $this->assertStringContainsString('Blue Ocean Customer', $html);
            $this->assertStringContainsString('Warehouse handling service', $html);
            $this->assertStringContainsString('USD 500.00', $html);
            $this->assertStringNotContainsString('>Currency<', $html);
        }
    }
}
