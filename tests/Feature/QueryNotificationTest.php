<?php

namespace Tests\Feature;

use App\Mail\InvoiceQueryRaised;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A query is recorded whether or not its email gets out, so the notification is queued (retryable,
 * and it cannot fail the request) and Finance can send it again — the recovery that was missing when
 * a rotated SMTP password silently swallowed the notification.
 */
class QueryNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $requester;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->finance = $this->user(User::ROLE_FINANCE);
        $this->requester = $this->user(User::ROLE_REQUESTER);
        $this->invoice = $this->postedInvoice();
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.uniqid().'@t.local',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function postedInvoice(): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Vendor', 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 500, 'tax_amount' => 0, 'total_amount' => 500,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->requester->id, 'submitted_at' => now(),
        ]);
    }

    public function test_every_mailable_is_queued_rather_than_sent_inline(): void
    {
        foreach (glob(app_path('Mail/*.php')) as $file) {
            $class = 'App\\Mail\\'.basename($file, '.php');
            $this->assertTrue(
                is_subclass_of($class, ShouldQueue::class),
                "{$class} sends inline, so an SMTP fault would break the request that triggered it.",
            );
        }
    }

    public function test_raising_a_query_queues_the_notification_to_the_submitter(): void
    {
        $this->actingAs($this->finance)
            ->postJson("/api/invoices/{$this->invoice->id}/query", ['finance_remarks' => 'PO reference missing'])
            ->assertOk()
            ->assertJsonPath('notification_sent', true);

        Mail::assertQueued(InvoiceQueryRaised::class, fn ($mail) => $mail->hasTo($this->requester->email));
    }

    public function test_a_notification_failure_does_not_fail_the_query(): void
    {
        // What a rotated SMTP password looks like from the caller's side.
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('535 Authentication failed'));

        $this->actingAs($this->finance)
            ->postJson("/api/invoices/{$this->invoice->id}/query", ['finance_remarks' => 'PO reference missing'])
            ->assertOk()
            ->assertJsonPath('notification_sent', false);

        // The query itself still stuck, which is what the resend action then works from.
        $this->invoice->refresh();
        $this->assertSame(Invoice::STATUS_QUERY, $this->invoice->status);
        $this->assertSame('PO reference missing', $this->invoice->finance_remarks);
    }

    public function test_finance_can_resend_the_notification(): void
    {
        $this->invoice->update(['status' => Invoice::STATUS_QUERY, 'finance_remarks' => 'PO reference missing']);

        $this->actingAs($this->finance)
            ->postJson("/api/invoices/{$this->invoice->id}/resend-query")
            ->assertOk()
            ->assertJsonPath('notification_sent', true)
            ->assertJsonPath('sent_to', $this->requester->email);

        Mail::assertQueued(InvoiceQueryRaised::class, fn ($mail) => $mail->hasTo($this->requester->email));

        // Nothing about the invoice changes; the resend is recorded separately from the query.
        $this->invoice->refresh();
        $this->assertSame(Invoice::STATUS_QUERY, $this->invoice->status);
        $this->assertSame('PO reference missing', $this->invoice->finance_remarks);
        $this->assertNotNull(AuditLog::where('action', 'query_notification_resent')->first());
    }

    public function test_resending_needs_an_open_query(): void
    {
        $this->actingAs($this->finance)
            ->postJson("/api/invoices/{$this->invoice->id}/resend-query")
            ->assertStatus(422);

        Mail::assertNothingQueued();
    }

    public function test_resending_is_finance_and_admin_only(): void
    {
        $this->invoice->update(['status' => Invoice::STATUS_QUERY, 'finance_remarks' => 'PO reference missing']);

        foreach ([$this->requester, $this->user(User::ROLE_APPROVER)] as $user) {
            $this->actingAs($user)
                ->postJson("/api/invoices/{$this->invoice->id}/resend-query")
                ->assertForbidden();
        }

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/invoices/{$this->invoice->id}/resend-query")
            ->assertOk();
    }

    public function test_a_submitter_without_an_email_is_reported_rather_than_crashing(): void
    {
        $this->invoice->update(['status' => Invoice::STATUS_QUERY, 'finance_remarks' => 'PO reference missing']);
        // An account can exist without a usable address; the endpoint must say so, not 500.
        $this->requester->forceFill(['email' => ''])->save();

        $this->actingAs($this->finance)
            ->postJson("/api/invoices/{$this->invoice->id}/resend-query")
            ->assertStatus(422);

        Mail::assertNothingQueued();
    }

    public function test_a_failed_resend_reports_itself_and_is_not_audited_as_sent(): void
    {
        $this->invoice->update(['status' => Invoice::STATUS_QUERY, 'finance_remarks' => 'PO reference missing']);

        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('535 Authentication failed'));

        $this->actingAs($this->finance)
            ->postJson("/api/invoices/{$this->invoice->id}/resend-query")
            ->assertOk()
            ->assertJsonPath('notification_sent', false);

        $this->assertNull(AuditLog::where('action', 'query_notification_resent')->first());
    }
}
