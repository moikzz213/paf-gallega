<?php

namespace Tests\Feature;

use App\Mail\PaymentRequestApproved;
use App\Mail\PaymentRequestQueryRaised;
use App\Mail\PaymentRequestRejected;
use App\Mail\PaymentRequestReminder;
use App\Mail\PaymentRequestSubmitted;
use App\Models\AuditLog;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceRejection;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentRequestService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A payment request keeps the currency it was raised in after its invoices leave it; a currency that
 * cannot be proven is blank, never AED; and dashboard and report totals never add one currency to
 * another.
 *
 * CR: ai/change-requests/fix-wrong-currency-on-payment-requests-and-dashboard-totals.md
 */
class PaymentRequestCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::updateOrCreate(['name' => 'AED'], ['exchange_rate' => 1, 'is_active' => true]);
        Currency::updateOrCreate(['name' => 'USD'], ['exchange_rate' => 3.6725, 'is_active' => true]);
        Currency::updateOrCreate(['name' => 'OMR'], ['exchange_rate' => 9.539209, 'is_active' => true]);

        $this->finance = $this->user(User::ROLE_FINANCE);
        $this->approver = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
    }

    private function user(string $role, array $extra = []): User
    {
        return User::create([
            'name' => ucfirst($role).' '.uniqid(), 'email' => $role.uniqid().'@t.local',
            'password' => 'password', 'role' => $role, 'is_active' => true, ...$extra,
        ]);
    }

    private function invoice(string $currency, float $amount, array $extra = []): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Ocean Lines', 'invoice_no' => 'V-'.uniqid(), 'invoice_date' => now()->toDateString(),
            'currency' => $currency, 'amount' => $amount, 'tax_amount' => 0, 'total_amount' => $amount,
            'business_unit' => 'GGH', 'department' => 'Yard', 'location' => 'Dubai',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->finance->id, 'submitted_at' => now(),
            ...$extra,
        ]);
    }

    private function raise(Invoice ...$invoices): PaymentRequest
    {
        return app(PaymentRequestService::class)->create(collect($invoices)->pluck('id')->all(), $this->finance, [
            ['approver_id' => $this->approver->id, 'label' => 'Manager', 'level' => 1, 'is_adhoc' => false],
        ]);
    }

    /** A request as it was before requests recorded their currency, with its invoices gone. */
    private function legacyEmptyRequest(string $status): PaymentRequest
    {
        $pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(), 'created_by' => $this->finance->id,
            'status' => $status, 'total_amount' => 7400, 'sent_at' => now(),
        ]);
        PaymentRequestApproval::create([
            'payment_request_id' => $pr->id, 'sequence' => 1, 'label' => 'Manager',
            'approver_id' => $this->approver->id, 'status' => PaymentRequestApproval::STATUS_PENDING,
        ]);

        return $pr;
    }

    public function test_a_request_records_its_currency_and_keeps_it_once_its_invoices_are_returned(): void
    {
        $pr = $this->raise($this->invoice('USD', 7400));
        $this->assertSame('USD', $pr->getRawOriginal('currency'));

        app(PaymentRequestService::class)->approve($pr->refresh(), $this->approver);
        app(PaymentRequestService::class)->withdraw($pr->refresh(), $this->finance, 'Wrong period');

        $pr->refresh();
        $this->assertSame(0, $pr->invoices()->count(), 'the invoices have left the request');
        $this->assertSame('USD', $pr->currency);

        $this->actingAs($this->finance)->getJson("/api/payment-requests/{$pr->id}")
            ->assertOk()->assertJsonPath('currency', 'USD');

        $page = $this->get("/prf/view/{$pr->id}/{$pr->view_token}")->assertOk();
        $page->assertSee('USD 7,400.00')->assertDontSee('AED 7,400.00');
    }

    /**
     * Every email that states a request's amount, and the emailed approval page, in the request's
     * own currency — for a USD request, nothing anywhere may read AED.
     */
    public function test_emails_and_the_approval_page_show_the_request_currency(): void
    {
        $pr = $this->raise($this->invoice('USD', 7654.50))->refresh();
        $approval = $pr->approvals()->first();

        $query = $pr->queries()->create([
            'payment_request_approval_id' => $approval->id, 'sequence' => 1, 'stage_label' => 'Manager',
            'raised_by' => $this->approver->id, 'question' => 'PO?', 'raised_at' => now(),
        ]);

        $mails = [
            'approval required' => new PaymentRequestSubmitted($pr, $approval),
            'reminder' => new PaymentRequestReminder($pr),
            'approval query' => new PaymentRequestQueryRaised($pr, $query),
            'fully approved' => new PaymentRequestApproved($pr),
        ];

        foreach ($mails as $name => $mail) {
            // Through the queue round trip, as a worker renders it.
            $mail = unserialize(serialize($mail));
            $html = $mail->render();

            $this->assertStringContainsString('USD 7,654.50', $html, "{$name} email");
            $this->assertStringNotContainsString('AED', $html, "{$name} email");
            $this->assertStringContainsString('USD 7,654.50', $mail->envelope()->subject, "{$name} subject");
        }

        $token = $approval->view_token;
        $this->get("/prf/view/{$pr->id}/{$token}")->assertOk()
            ->assertSee('USD 7,654.50')->assertDontSee('AED 7,654.50');
    }

    /**
     * The production report: a rejection notice rendered after its invoices had been returned showed
     * "AED 22,875.00" above invoice lines in USD. A notice still queued for a request with no recorded
     * currency now takes it from the lines it captured.
     */
    public function test_a_queued_rejection_notice_shows_the_total_in_the_invoices_currency(): void
    {
        $pr = $this->legacyEmptyRequest(PaymentRequest::STATUS_REJECTED);
        $pr->update(['total_amount' => 22875, 'rejection_reason' => 'customer payment proof is required', 'rejected_at' => now()]);
        $this->assertSame('', $pr->refresh()->currency, 'no recorded currency and no invoices left');

        $mail = unserialize(serialize(new PaymentRequestRejected($pr, "Freight - FILENDERS - USD 22,875.00 - {$pr->reference_no}", [[
            'reference_no' => 'INV-2026-01463', 'vendor_name' => 'FILENDERS LOJISTIK', 'invoice_no' => 'F-1',
            'total_amount' => 22875, 'currency' => 'USD',
        ]])));
        $html = $mail->render();

        $this->assertStringContainsString('USD 22,875.00', $html);
        $this->assertStringNotContainsString('AED 22,875.00', $html);
        $this->assertStringNotContainsString('AED', $html);
    }

    public function test_an_unknown_currency_is_blank_never_aed(): void
    {
        $pr = $this->legacyEmptyRequest(PaymentRequest::STATUS_REJECTED);

        $this->assertSame('', $pr->refresh()->currency);
        $this->assertSame('7,400.00', Money::format($pr->total_amount, $pr->currency));
        $this->assertSame('(5.00)', Money::format(-5, ''));
        $this->assertSame('AED 5.00', Money::format(5), 'an omitted currency still defaults');

        $this->actingAs($this->finance)->getJson("/api/payment-requests/{$pr->id}")->assertJsonPath('currency', '');
        $this->get("/prf/view/{$pr->id}/{$pr->view_token}")->assertOk()->assertDontSee('AED 7,400.00');
    }

    public function test_the_repair_previews_by_default_and_records_only_what_history_proves(): void
    {
        // Rejected, with rejection history over a USD invoice.
        $rejected = $this->legacyEmptyRequest(PaymentRequest::STATUS_REJECTED);
        InvoiceRejection::create(['invoice_id' => $this->invoice('USD', 7400)->id, 'payment_request_id' => $rejected->id, 'rejected_at' => now()]);

        // Withdrawn, its invoices named only in the withdrawal entry of its audit trail.
        $withdrawn = $this->legacyEmptyRequest(PaymentRequest::STATUS_WITHDRAWN);
        $omr = $this->invoice('OMR', 19500);
        AuditLogger::log('withdrawn', "Payment request {$withdrawn->reference_no} withdrawn after approval: x. Invoices returned to Finance: {$omr->reference_no}.", null, null, null, $withdrawn);

        // Rejected before rejection history existed: nothing proves its currency.
        $unknown = $this->legacyEmptyRequest(PaymentRequest::STATUS_REJECTED);

        // History points at an invoice whose currency has changed since — not firm evidence.
        $changed = $this->legacyEmptyRequest(PaymentRequest::STATUS_REJECTED);
        $moved = $this->invoice('USD', 100);
        InvoiceRejection::create(['invoice_id' => $moved->id, 'payment_request_id' => $changed->id, 'rejected_at' => now()]);
        AuditLogger::log('corrected_after_posting', 'Corrected', $moved, ['currency' => 'AED'], ['currency' => 'USD']);

        $this->artisan('paf:repair-request-currencies')->assertSuccessful();
        $this->assertNull($rejected->refresh()->getRawOriginal('currency'), 'a preview writes nothing');

        $this->artisan('paf:repair-request-currencies', ['--apply' => true])->assertSuccessful();

        $this->assertSame('USD', $rejected->refresh()->getRawOriginal('currency'));
        $this->assertSame('OMR', $withdrawn->refresh()->getRawOriginal('currency'));
        $this->assertNull($unknown->refresh()->getRawOriginal('currency'));
        $this->assertNull($changed->refresh()->getRawOriginal('currency'));
        $this->assertSame('', $unknown->currency);

        $this->assertSame(2, AuditLog::where('action', 'currency_recorded')->count());
    }

    public function test_a_blank_currency_is_set_by_hand_once_confirmed(): void
    {
        $pr = $this->legacyEmptyRequest(PaymentRequest::STATUS_REJECTED);

        $this->artisan('paf:set-request-currency', ['reference' => $pr->reference_no, 'currency' => 'XYZ', '--confirmed-by' => 'A'])->assertFailed();
        $this->artisan('paf:set-request-currency', ['reference' => $pr->reference_no, 'currency' => 'USD'])->assertFailed();
        $this->assertNull($pr->refresh()->getRawOriginal('currency'));

        $this->artisan('paf:set-request-currency', ['reference' => $pr->reference_no, 'currency' => 'usd', '--confirmed-by' => 'Grace Tan, Finance'])
            ->assertSuccessful();

        $this->assertSame('USD', $pr->refresh()->currency);
        $this->assertTrue(AuditLog::where('action', 'currency_recorded')->where('description', 'like', '%confirmed by Grace Tan, Finance%')->exists());

        // A currency contradicting the invoices still on a request is refused.
        $live = $this->raise($this->invoice('AED', 500));
        $this->artisan('paf:set-request-currency', ['reference' => $live->reference_no, 'currency' => 'USD', '--confirmed-by' => 'A'])->assertFailed();
    }

    public function test_dashboard_cards_show_a_total_per_currency_and_charts_convert_to_aed(): void
    {
        $this->invoice('AED', 1000, ['status' => Invoice::STATUS_SUBMITTED]);
        $this->invoice('AED', 500, ['status' => Invoice::STATUS_SUBMITTED]);
        $this->invoice('USD', 100, ['status' => Invoice::STATUS_SUBMITTED]);

        $res = $this->actingAs($this->finance)->getJson('/api/dashboard?all=1')->assertOk();

        $this->assertSame(3, $res->json('cards.awaiting_posting.count'));
        $this->assertEquals(
            [['currency' => 'AED', 'count' => 2, 'amount' => 1500], ['currency' => 'USD', 'count' => 1, 'amount' => 100]],
            $res->json('cards.awaiting_posting.amounts'),
        );

        $this->assertSame('AED', $res->json('chart_currency'));
        $this->assertEqualsWithDelta(1500 + 367.25, collect($res->json('monthly'))->sum('submitted'), 0.01);
        $this->assertEqualsWithDelta(1867.25, $res->json('top_vendors.0.amount'), 0.01);
        $this->assertSame([], $res->json('unconverted_currencies'));
    }

    public function test_a_currency_without_a_rate_is_left_out_of_the_charts_and_named(): void
    {
        // A currency with no rate on record at all.
        $this->invoice('KWD', 100, ['status' => Invoice::STATUS_SUBMITTED]);
        $this->invoice('AED', 50, ['status' => Invoice::STATUS_SUBMITTED]);

        $res = $this->actingAs($this->finance)->getJson('/api/dashboard?all=1')->assertOk();

        $this->assertSame(['KWD'], $res->json('unconverted_currencies'));
        $this->assertEqualsWithDelta(50, collect($res->json('monthly'))->sum('submitted'), 0.01);
        $this->assertCount(2, $res->json('cards.awaiting_posting.amounts'), 'the card still shows the KWD total');
    }

    public function test_report_totals_are_kept_apart_per_currency(): void
    {
        $this->invoice('AED', 1000);
        $this->invoice('USD', 100);

        $res = $this->actingAs($this->finance)->getJson('/api/reports')->assertOk();

        $this->assertSame(2, $res->json('totals.count'));
        $this->assertEqualsCanonicalizing(['AED', 'USD'], collect($res->json('totals.amounts'))->pluck('currency')->all());
        $this->assertCount(2, $res->json('summary.0.amounts'));
    }
}
