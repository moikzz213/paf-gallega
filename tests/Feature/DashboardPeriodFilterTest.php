<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Dashboard reports every card and chart against one selected period, where before it mixed
 * all-time cards with a this-month card and a six-month chart.
 *
 * The cases that matter most are the ones that look like defects but are not: each metric is
 * anchored on its own date, so an invoice paid inside the period but submitted before it counts
 * towards Paid and not towards Total Invoices. The approver queue is deliberately left outside the
 * filter — hiding a pending approval behind a date range would mean real work going unseen.
 *
 * Test cases: ai/test-cases/add-dashboard-period-filter.md
 */
class DashboardPeriodFilterTest extends TestCase
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

    /**
     * @param  string|null  $submittedAt  null models a legacy row with no submission timestamp
     * @param  string|null  $paidAt  when set, the invoice is paid through a payment request
     */
    private function invoice(?string $submittedAt, ?string $paidAt = null, ?string $invoiceDate = null, array $attributes = []): Invoice
    {
        $paymentRequestId = null;

        if ($paidAt !== null || array_key_exists('force_request', $attributes)) {
            $paymentRequestId = PaymentRequest::create([
                'reference_no' => PaymentRequest::nextReferenceNo(),
                'created_by' => $this->finance->id,
                'status' => PaymentRequest::STATUS_PAID,
                'total_amount' => 100,
                'sent_at' => $submittedAt ?? now(),
                'paid_at' => $paidAt,
            ])->id;
        }

        unset($attributes['force_request']);

        return Invoice::create(array_merge([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Vendor', 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => $invoiceDate ?? ($submittedAt ?? '2026-01-15'),
            'currency' => 'AED', 'amount' => 100, 'tax_amount' => 0, 'total_amount' => 100,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED,
            'payment_status' => $paymentRequestId ? Invoice::PAY_PAID : Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->finance->id, 'submitted_at' => $submittedAt,
            'payment_request_id' => $paymentRequestId,
        ], $attributes));
    }

    private function dashboard(array $params = [])
    {
        return $this->actingAs($this->finance)->getJson('/api/dashboard?'.http_build_query($params));
    }

    public function test_it_defaults_to_the_year_to_date(): void
    {
        $res = $this->dashboard()->assertOk();

        $this->assertSame(now()->format('Y').'-01', $res->json('period.from'));
        $this->assertSame(now()->format('Y-m'), $res->json('period.to'));
        $this->assertFalse($res->json('period.all_time'));
    }

    public function test_an_explicit_range_narrows_every_card(): void
    {
        $this->invoice('2026-02-10 09:00:00');
        $this->invoice('2026-04-10 09:00:00');
        $this->invoice('2025-12-10 09:00:00');

        $res = $this->dashboard(['from' => '2026-01', 'to' => '2026-04'])->assertOk();

        $this->assertSame('2026-01', $res->json('period.from'));
        $this->assertSame('2026-04', $res->json('period.to'));
        $this->assertSame(2, $res->json('cards.total_invoices'));
        $this->assertCount(2, $res->json('recent'));
    }

    public function test_month_boundaries_are_inclusive_at_both_ends(): void
    {
        $this->invoice('2026-01-01 00:00:00');
        $this->invoice('2026-01-31 23:59:59');

        $this->assertSame(2, $this->dashboard(['from' => '2026-01', 'to' => '2026-01'])
            ->assertOk()->json('cards.total_invoices'));
    }

    public function test_paid_is_counted_by_payment_date_not_submission_date(): void
    {
        // Submitted well before the period, paid inside it: Paid counts it, Total Invoices does not.
        $this->invoice('2025-11-02 09:00:00', '2026-04-18 09:00:00');

        $res = $this->dashboard(['from' => '2026-04', 'to' => '2026-04'])->assertOk();

        $this->assertSame(1, $res->json('cards.paid.count'));
        $this->assertEquals(100, $res->json('cards.paid.amount'));
        $this->assertSame(0, $res->json('cards.total_invoices'));
    }

    public function test_spend_charts_are_counted_by_invoice_date(): void
    {
        // Invoice dated February, submitted in March: the spend charts follow the invoice date.
        $this->invoice('2026-03-10 09:00:00', null, '2026-02-25');

        $res = $this->dashboard(['from' => '2026-02', 'to' => '2026-02'])->assertOk();

        $this->assertSame(0, $res->json('cards.total_invoices'));
        $this->assertCount(1, $res->json('top_vendors'));
        $this->assertCount(1, $res->json('by_business_unit'));
    }

    public function test_all_time_ignores_the_period_entirely(): void
    {
        $this->invoice('2019-01-05 09:00:00');
        $this->invoice('2026-04-05 09:00:00');

        $res = $this->dashboard(['all' => 1])->assertOk();

        $this->assertTrue($res->json('period.all_time'));
        $this->assertNull($res->json('period.from'));
        $this->assertSame('all time', $res->json('period.label'));
        $this->assertSame(2, $res->json('cards.total_invoices'));
    }

    public function test_the_trend_chart_follows_the_span(): void
    {
        $months = $this->dashboard(['from' => '2026-01', 'to' => '2026-06'])->assertOk()->json('monthly');

        $this->assertCount(6, $months);
        $this->assertSame('Jan 2026', $months[0]['month']);
        $this->assertSame('Jun 2026', $months[5]['month']);
    }

    public function test_the_trend_chart_is_capped_on_a_long_span(): void
    {
        $months = $this->dashboard(['from' => '2015-01', 'to' => '2026-09'])->assertOk()->json('monthly');

        $this->assertCount(24, $months);
        // The cap keeps the most recent months of the span, not the oldest.
        $this->assertSame('Sep 2026', end($months)['month']);
    }

    public function test_an_inverted_range_is_swapped_rather_than_rejected(): void
    {
        $res = $this->dashboard(['from' => '2026-06', 'to' => '2026-01'])->assertOk();

        $this->assertSame('2026-01', $res->json('period.from'));
        $this->assertSame('2026-06', $res->json('period.to'));
    }

    public function test_an_excessive_span_is_capped(): void
    {
        $res = $this->dashboard(['from' => '1900-01', 'to' => '2026-12'])->assertOk();

        $this->assertSame('2017-01', $res->json('period.from'));
        $this->assertSame('2026-12', $res->json('period.to'));
    }

    public function test_a_malformed_month_is_rejected(): void
    {
        $this->dashboard(['from' => '2026-13'])->assertStatus(422)->assertJsonValidationErrors('from');
        $this->dashboard(['from' => 'not-a-date'])->assertStatus(422)->assertJsonValidationErrors('from');
        $this->dashboard(['from' => '2026-04-15'])->assertStatus(422)->assertJsonValidationErrors('from');
        $this->dashboard(['to' => "2026-01' OR '1'='1"])->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_one_bound_on_its_own_is_resolved_against_today(): void
    {
        $res = $this->dashboard(['from' => '2026-03'])->assertOk();

        $this->assertSame('2026-03', $res->json('period.from'));
        $this->assertSame(now()->format('Y-m'), $res->json('period.to'));
    }

    public function test_records_with_no_event_date_drop_out_of_a_bounded_period_but_not_all_time(): void
    {
        $this->invoice(null);                                    // legacy row, no submission date
        $this->invoice('2026-04-05 09:00:00', null, null, [      // paid, but payment date never recorded
            'payment_status' => Invoice::PAY_PAID,
            'force_request' => true,
        ]);

        $bounded = $this->dashboard(['from' => '2026-01', 'to' => '2026-12'])->assertOk();
        $this->assertSame(1, $bounded->json('cards.total_invoices'));
        $this->assertSame(0, $bounded->json('cards.paid.count'));

        $this->assertSame(2, $this->dashboard(['all' => 1])->assertOk()->json('cards.total_invoices'));
    }

    public function test_an_empty_period_returns_zeroes_rather_than_failing(): void
    {
        $this->invoice('2026-04-05 09:00:00');

        $res = $this->dashboard(['from' => '2020-01', 'to' => '2020-03'])->assertOk();

        $this->assertSame(0, $res->json('cards.total_invoices'));
        $this->assertEquals(0, $res->json('cards.awaiting_posting.amount'));
        $this->assertSame([], $res->json('top_vendors'));
        $this->assertSame([], $res->json('recent'));
    }

    public function test_the_approver_queue_is_never_hidden_by_the_period(): void
    {
        $approver = User::create([
            'name' => 'App', 'email' => 'app@t.local', 'password' => 'password',
            'role' => User::ROLE_APPROVER, 'is_active' => true,
        ]);

        $pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $this->finance->id,
            'status' => PaymentRequest::STATUS_IN_APPROVAL,
            'total_amount' => 100,
            'sent_at' => '2024-06-01 09:00:00',
            'current_stage' => 1,
        ]);
        $pr->approvals()->create([
            'sequence' => 1, 'level' => 1, 'label' => 'Finance Manager',
            'approver_id' => $approver->id, 'status' => 'pending',
        ]);

        // A period containing none of the approver's work must still show the live queue.
        $res = $this->actingAs($approver)
            ->getJson('/api/dashboard?from=2026-09&to=2026-09')
            ->assertOk();

        $this->assertSame(1, $res->json('my_queue'));
    }

    public function test_visibility_scoping_still_applies_at_every_period(): void
    {
        $requester = User::create([
            'name' => 'Req', 'email' => 'req@t.local', 'password' => 'password',
            'role' => User::ROLE_REQUESTER, 'is_active' => true,
        ]);

        $this->invoice('2026-04-05 09:00:00');                                          // finance's
        $this->invoice('2026-04-06 09:00:00', null, null, ['submitted_by' => $requester->id]);

        foreach ([['from' => '2026-01', 'to' => '2026-12'], ['all' => 1]] as $params) {
            $res = $this->actingAs($requester)->getJson('/api/dashboard?'.http_build_query($params))->assertOk();

            $this->assertSame(1, $res->json('cards.total_invoices'), 'widening the period must not widen visibility');
        }
    }
}
