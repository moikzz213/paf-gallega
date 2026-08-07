<?php

namespace Tests\Feature;

use App\Mail\PaymentRequestApproved;
use App\Mail\PaymentRequestSubmitted;
use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApprovalChainNotificationTest extends TestCase
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

    private function postedInvoice(User $owner, float $total): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Vendor', 'invoice_no' => 'SUP-'.uniqid(), 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $owner->id, 'submitted_at' => now(),
        ]);
    }

    /** @return array{pr: PaymentRequest, l1: User, l2: User, requester: User} */
    private function twoStageChain(): array
    {
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        ApprovalLevel::create(['level' => 2, 'name' => 'Director', 'min_amount' => 10000, 'is_active' => true]);

        $finance = $this->user(User::ROLE_FINANCE);
        $requester = $this->user(User::ROLE_REQUESTER);
        $l1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $l2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);

        $invoice = $this->postedInvoice($requester, 25000);

        $pr = app(PaymentRequestService::class)->create([$invoice->id], $finance, [
            ['approver_id' => $l1->id, 'label' => 'Manager', 'level' => 1, 'is_adhoc' => false],
            ['approver_id' => $l2->id, 'label' => 'Director', 'level' => 2, 'is_adhoc' => false],
        ]);

        return compact('pr', 'l1', 'l2', 'requester');
    }

    public function test_creating_a_request_notifies_only_the_first_approver(): void
    {
        Mail::fake();
        ['l1' => $l1, 'l2' => $l2] = $this->twoStageChain();

        Mail::assertSent(PaymentRequestSubmitted::class, fn ($m) => $m->hasTo($l1->email));
        Mail::assertNotSent(PaymentRequestSubmitted::class, fn ($m) => $m->hasTo($l2->email));
    }

    /**
     * The reported defect: approving in the app advanced the chain but never told the next
     * approver, so an L2 approver only found out by chance. The public link path did notify.
     */
    public function test_approving_in_app_notifies_the_next_approver(): void
    {
        ['pr' => $pr, 'l1' => $l1, 'l2' => $l2] = $this->twoStageChain();

        Mail::fake();

        $this->actingAs($l1)
            ->postJson("/api/payment-requests/{$pr->id}/approve", ['comments' => 'ok'])
            ->assertOk();

        $this->assertSame(2, $pr->refresh()->current_stage);
        Mail::assertSent(PaymentRequestSubmitted::class, fn ($m) => $m->hasTo($l2->email));
    }

    public function test_an_admin_approving_on_behalf_still_notifies_the_next_approver(): void
    {
        ['pr' => $pr, 'l2' => $l2] = $this->twoStageChain();
        $admin = $this->user(User::ROLE_ADMIN);

        Mail::fake();

        $this->actingAs($admin)
            ->postJson("/api/payment-requests/{$pr->id}/approve", [])
            ->assertOk();

        Mail::assertSent(PaymentRequestSubmitted::class, fn ($m) => $m->hasTo($l2->email));
    }

    public function test_the_final_approval_notifies_requestors_not_a_next_approver(): void
    {
        ['pr' => $pr, 'l1' => $l1, 'l2' => $l2, 'requester' => $requester] = $this->twoStageChain();

        $this->actingAs($l1)->postJson("/api/payment-requests/{$pr->id}/approve", [])->assertOk();

        Mail::fake();

        $this->actingAs($l2)->postJson("/api/payment-requests/{$pr->id}/approve", [])->assertOk();

        $this->assertSame(PaymentRequest::STATUS_APPROVED, $pr->refresh()->status);
        Mail::assertSent(PaymentRequestApproved::class, fn ($m) => $m->hasTo($requester->email));
        Mail::assertNotSent(PaymentRequestSubmitted::class);
    }

    /** The public link path had this behaviour already; it must keep it after the refactor. */
    public function test_approving_via_the_public_link_notifies_the_next_approver(): void
    {
        ['pr' => $pr, 'l2' => $l2] = $this->twoStageChain();

        // Each stage carries its own view_token, so stage 1's token is what L1 receives.
        $stageOneToken = $pr->approvals()->where('sequence', 1)->value('view_token');

        Mail::fake();

        $this->post("/prf/view/{$pr->id}/{$stageOneToken}/approve")->assertRedirect();

        $this->assertSame(2, $pr->refresh()->current_stage);
        Mail::assertSent(PaymentRequestSubmitted::class, fn ($m) => $m->hasTo($l2->email));
    }

    /**
     * The link in the email must carry the next approver's own token. Sending after the stage
     * advances is what makes currentApproval() resolve to them rather than the previous approver.
     */
    public function test_the_notification_links_with_the_next_approvers_own_token(): void
    {
        ['pr' => $pr, 'l1' => $l1] = $this->twoStageChain();
        $stageTwoToken = $pr->approvals()->where('sequence', 2)->value('view_token');
        $stageOneToken = $pr->approvals()->where('sequence', 1)->value('view_token');

        Mail::fake();

        $this->actingAs($l1)->postJson("/api/payment-requests/{$pr->id}/approve", [])->assertOk();

        Mail::assertSent(PaymentRequestSubmitted::class, function ($mail) use ($stageTwoToken, $stageOneToken) {
            $body = $mail->render();

            return str_contains($body, $stageTwoToken) && ! str_contains($body, $stageOneToken);
        });
    }

    public function test_rejecting_notifies_no_further_approver(): void
    {
        ['pr' => $pr, 'l1' => $l1, 'l2' => $l2] = $this->twoStageChain();

        Mail::fake();

        $this->actingAs($l1)
            ->postJson("/api/payment-requests/{$pr->id}/reject", ['comments' => 'wrong vendor'])
            ->assertOk();

        Mail::assertNotSent(PaymentRequestSubmitted::class, fn ($m) => $m->hasTo($l2->email));
    }
}
