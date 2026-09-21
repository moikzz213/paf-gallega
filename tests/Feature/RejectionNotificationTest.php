<?php

namespace Tests\Feature;

use App\Mail\PaymentRequestRejected;
use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rejection used to be silent. The requestor's invoices dropped back to "not initiated" with no
 * signal, so the reason the approver typed was only visible by reopening the request.
 */
class RejectionNotificationTest extends TestCase
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

    /** @return array{pr: PaymentRequest, l1: User, finance: User, requester: User} */
    private function chain(): array
    {
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);

        $finance = $this->user(User::ROLE_FINANCE);
        $requester = $this->user(User::ROLE_REQUESTER);
        $l1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Acme Trading', 'invoice_no' => 'SUP-'.uniqid(), 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 5000, 'tax_amount' => 0, 'total_amount' => 5000,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $requester->id, 'submitted_at' => now(),
        ]);

        $pr = app(PaymentRequestService::class)->create([$invoice->id], $finance, [
            ['approver_id' => $l1->id, 'label' => 'Manager', 'level' => 1, 'is_adhoc' => false],
        ]);

        return compact('pr', 'l1', 'finance', 'requester');
    }

    public function test_rejecting_in_app_notifies_the_creator_and_the_invoice_submitter(): void
    {
        ['pr' => $pr, 'l1' => $l1, 'finance' => $finance, 'requester' => $requester] = $this->chain();

        Mail::fake();

        $this->actingAs($l1)
            ->postJson("/api/payment-requests/{$pr->id}/reject", ['comments' => 'Vendor bank details are wrong'])
            ->assertOk();

        Mail::assertQueued(PaymentRequestRejected::class, fn ($m) => $m->hasTo($requester->email));
        Mail::assertQueued(PaymentRequestRejected::class, fn ($m) => $m->hasTo($finance->email));
    }

    public function test_the_notice_carries_the_rejection_reason_the_approver_typed(): void
    {
        ['pr' => $pr, 'l1' => $l1] = $this->chain();

        Mail::fake();

        $this->actingAs($l1)
            ->postJson("/api/payment-requests/{$pr->id}/reject", ['comments' => 'Vendor bank details are wrong'])
            ->assertOk();

        Mail::assertQueued(PaymentRequestRejected::class, function ($mail) use ($l1) {
            $body = $mail->render();

            return str_contains($body, 'Vendor bank details are wrong')
                && str_contains($body, 'Reason for rejection')
                && str_contains($body, $l1->name)
                && str_contains($body, 'Manager');
        });
    }

    /**
     * The trap this feature has to survive: rejection detaches the invoices, so a queued mailable
     * that re-read the relation would render an empty list and a "Vendor not recorded" subject.
     */
    public function test_the_notice_still_describes_the_invoices_that_rejection_detached(): void
    {
        ['pr' => $pr, 'l1' => $l1] = $this->chain();
        $invoiceRef = $pr->invoices()->value('reference_no');

        Mail::fake();

        $this->actingAs($l1)
            ->postJson("/api/payment-requests/{$pr->id}/reject", ['comments' => 'Duplicate submission'])
            ->assertOk();

        $this->assertSame(0, $pr->refresh()->invoices()->count(), 'rejection should detach the invoices');

        Mail::assertQueued(PaymentRequestRejected::class, function ($mail) use ($invoiceRef) {
            $body = $mail->render();

            return str_contains($body, $invoiceRef)
                && str_contains($body, 'Acme Trading')
                && str_contains($mail->envelope()->subject, 'Acme Trading');
        });
    }

    public function test_rejecting_via_the_public_link_sends_the_same_notice(): void
    {
        ['pr' => $pr, 'requester' => $requester] = $this->chain();
        $token = $pr->approvals()->where('sequence', 1)->value('view_token');

        Mail::fake();

        $this->post("/prf/view/{$pr->id}/{$token}/reject", ['comments' => 'Missing purchase order'])
            ->assertRedirect();

        $this->assertSame(PaymentRequest::STATUS_REJECTED, $pr->refresh()->status);

        Mail::assertQueued(PaymentRequestRejected::class, function ($mail) use ($requester) {
            return $mail->hasTo($requester->email) && str_contains($mail->render(), 'Missing purchase order');
        });
    }
}
