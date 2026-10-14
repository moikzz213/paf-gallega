<?php

namespace Tests\Feature;

use App\Mail\PaymentRequestQueryRaised;
use App\Mail\PaymentRequestReminder;
use App\Mail\PaymentRequestSubmitted;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use App\Models\User;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Approvers raise an Approval Query instead of rejecting: the request goes on hold at their stage,
 * the people concerned get one BCC email, and once the submitter, Finance or an admin answers and
 * resubmits, it returns to the same stage with the earlier approvals intact.
 *
 * CR: ai/change-requests/replace-reject-with-query-in-payment-approval.md
 */
class ApprovalQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $otherFinance;

    private User $submitterA;

    private User $submitterB;

    /** @var array<int, User> approvers by stage, 1-based */
    private array $approvers = [];

    private PaymentRequest $pr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = $this->user(User::ROLE_FINANCE);
        $this->otherFinance = $this->user(User::ROLE_FINANCE);
        $this->submitterA = $this->user(User::ROLE_REQUESTER);
        $this->submitterB = $this->user(User::ROLE_REQUESTER);

        foreach (range(1, 5) as $stage) {
            $this->approvers[$stage] = $this->user(User::ROLE_APPROVER, ['approval_level' => min($stage, 4)]);
        }

        $this->pr = app(PaymentRequestService::class)->create(
            [$this->invoice($this->submitterA)->id, $this->invoice($this->submitterB)->id],
            $this->finance,
            collect($this->approvers)->map(fn (User $u, int $stage) => [
                'approver_id' => $u->id, 'label' => "Stage {$stage} approver", 'level' => null, 'is_adhoc' => false,
            ])->values()->all(),
        );
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

    private function invoice(User $submitter, float $amount = 5000): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Acme Trading', 'invoice_no' => 'SUP-'.uniqid(), 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => $amount, 'tax_amount' => 0, 'total_amount' => $amount,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $submitter->id, 'submitted_at' => now(),
        ]);
    }

    /** Approve every stage before $stage, so the request is waiting on it. */
    private function advanceTo(int $stage): void
    {
        for ($s = 1; $s < $stage; $s++) {
            app(PaymentRequestService::class)->approve($this->pr->refresh(), $this->approvers[$s]);
        }

        $this->assertSame($stage, $this->pr->refresh()->current_stage);
    }

    private function raiseQueryAt(int $stage, string $question = 'Where is the purchase order?'): void
    {
        $this->advanceTo($stage);

        $this->actingAs($this->approvers[$stage])
            ->postJson("/api/payment-requests/{$this->pr->id}/query", ['question' => $question])
            ->assertOk();
    }

    /** @return array<int, string> */
    private function bccOf(PaymentRequestQueryRaised $mail): array
    {
        return collect($mail->bcc)->pluck('address')->sort()->values()->all();
    }

    /** @param  array<int, User>  $users */
    private function emails(array $users): array
    {
        return collect($users)->pluck('email')->map(fn ($e) => strtolower($e))->sort()->values()->all();
    }

    public function test_a_query_puts_the_request_on_hold_at_the_same_stage_and_keeps_its_invoices(): void
    {
        Mail::fake();

        $this->raiseQueryAt(2);

        $this->pr->refresh();
        $this->assertSame(PaymentRequest::STATUS_QUERIED, $this->pr->status);
        $this->assertSame(2, $this->pr->current_stage);
        $this->assertSame(2, $this->pr->invoices()->where('payment_status', Invoice::PAY_IN_APPROVAL)->count());
        $this->assertSame(PaymentRequestApproval::STATUS_APPROVED, $this->pr->approvals()->where('sequence', 1)->value('status'));
        $this->assertSame(PaymentRequestApproval::STATUS_PENDING, $this->pr->approvals()->where('sequence', 2)->value('status'));

        $query = $this->pr->openQuery();
        $this->assertSame('Where is the purchase order?', $query->question);
        $this->assertSame($this->approvers[2]->id, $query->raised_by);
        $this->assertSame(2, $query->sequence);

        $this->assertTrue(AuditLog::where('action', 'approval_query_raised')->where('payment_request_id', $this->pr->id)->exists());
    }

    public function test_level_1_query_emails_the_submitters_and_the_finance_user_who_raised_the_request(): void
    {
        Mail::fake();

        $this->raiseQueryAt(1);

        Mail::assertQueuedCount(1);
        $mail = Mail::queued(PaymentRequestQueryRaised::class)->sole();

        $this->assertTrue($mail->hasTo(config('mail.from.address')), 'addressed to the system mailbox');
        $this->assertSame($this->emails([$this->submitterA, $this->submitterB, $this->finance]), $this->bccOf($mail));
    }

    public function test_level_2_query_adds_the_level_1_approver(): void
    {
        Mail::fake();

        $this->raiseQueryAt(2);

        $this->assertSame(
            $this->emails([$this->submitterA, $this->submitterB, $this->finance, $this->approvers[1]]),
            $this->bccOf(Mail::queued(PaymentRequestQueryRaised::class)->sole()),
        );
    }

    public function test_level_3_query_adds_the_level_1_and_2_approvers_but_not_the_one_who_asked(): void
    {
        Mail::fake();

        $this->raiseQueryAt(3);

        $this->assertSame(
            $this->emails([$this->submitterA, $this->submitterB, $this->finance, $this->approvers[1], $this->approvers[2]]),
            $this->bccOf(Mail::queued(PaymentRequestQueryRaised::class)->sole()),
        );
    }

    public function test_level_4_query_also_copies_the_approver_who_asked(): void
    {
        Mail::fake();

        $this->raiseQueryAt(4);

        $this->assertSame(
            $this->emails([
                $this->submitterA, $this->submitterB, $this->finance,
                $this->approvers[1], $this->approvers[2], $this->approvers[3], $this->approvers[4],
            ]),
            $this->bccOf(Mail::queued(PaymentRequestQueryRaised::class)->sole()),
        );
    }

    public function test_level_5_query_copies_every_approver_so_far(): void
    {
        Mail::fake();

        $this->raiseQueryAt(5);

        $this->assertSame(
            $this->emails([$this->submitterA, $this->submitterB, $this->finance, ...array_values($this->approvers)]),
            $this->bccOf(Mail::queued(PaymentRequestQueryRaised::class)->sole()),
        );
        Mail::assertNotQueued(PaymentRequestQueryRaised::class, fn ($m) => $m->hasBcc($this->otherFinance->email));
    }

    public function test_someone_in_two_roles_is_listed_once_and_deactivated_users_are_left_out(): void
    {
        // The Finance user who raised the request also submitted one of its invoices.
        $this->pr->invoices()->first()->update(['submitted_by' => $this->finance->id]);
        $this->submitterB->update(['is_active' => false]);

        Mail::fake();

        $this->raiseQueryAt(1);

        $this->assertSame($this->emails([$this->finance]), $this->bccOf(Mail::queued(PaymentRequestQueryRaised::class)->sole()));
    }

    public function test_nobody_can_approve_a_queried_request_on_either_route(): void
    {
        Mail::fake();
        $this->raiseQueryAt(1);
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($this->approvers[1])->postJson("/api/payment-requests/{$this->pr->id}/approve", [])->assertStatus(422);
        $this->actingAs($admin)->postJson("/api/payment-requests/{$this->pr->id}/approve", [])->assertStatus(422);
        $this->actingAs($this->approvers[1])->postJson("/api/payment-requests/{$this->pr->id}/query", ['question' => 'again'])->assertStatus(422);

        $token = $this->pr->approvals()->where('sequence', 1)->value('view_token');
        $this->post("/prf/view/{$this->pr->id}/{$token}/approve")->assertRedirect()->assertSessionHas('error');

        $this->assertSame(PaymentRequest::STATUS_QUERIED, $this->pr->refresh()->status);
        $this->assertSame(PaymentRequestApproval::STATUS_PENDING, $this->pr->approvals()->where('sequence', 1)->value('status'));

        // And it leaves the approver's queue until it is resubmitted.
        $this->actingAs($this->approvers[1])->getJson('/api/payment-requests/pending')->assertJsonCount(0, 'data');
    }

    public function test_the_submitter_resubmits_and_the_request_returns_to_the_approver_who_asked(): void
    {
        $this->raiseQueryAt(3);
        Mail::fake();

        $this->actingAs($this->submitterA)
            ->postJson("/api/payment-requests/{$this->pr->id}/resubmit", ['response' => 'PO attached as PO-123.'])
            ->assertOk();

        $this->pr->refresh();
        $this->assertSame(PaymentRequest::STATUS_IN_APPROVAL, $this->pr->status);
        $this->assertSame(3, $this->pr->current_stage);
        $this->assertSame(2, $this->pr->approvals()->where('status', PaymentRequestApproval::STATUS_APPROVED)->count());
        $this->assertNull($this->pr->openQuery());

        $query = $this->pr->queries()->sole();
        $this->assertSame('PO attached as PO-123.', $query->response);
        $this->assertSame($this->submitterA->id, $query->resubmitted_by);

        Mail::assertQueued(PaymentRequestSubmitted::class, fn ($m) => $m->hasTo($this->approvers[3]->email));
        $this->assertTrue(AuditLog::where('action', 'approval_query_resubmitted')->exists());

        // The approver who asked can now decide.
        $this->actingAs($this->approvers[3])->postJson("/api/payment-requests/{$this->pr->id}/approve", [])->assertOk();
        $this->assertSame(4, $this->pr->refresh()->current_stage);
    }

    public function test_finance_and_admin_may_resubmit_but_approvers_and_outsiders_may_not(): void
    {
        $this->raiseQueryAt(2);
        $outsider = $this->user(User::ROLE_REQUESTER);

        $this->actingAs($this->approvers[2])->postJson("/api/payment-requests/{$this->pr->id}/resubmit", ['response' => 'x'])->assertForbidden();
        $this->actingAs($this->approvers[1])->postJson("/api/payment-requests/{$this->pr->id}/resubmit", ['response' => 'x'])->assertForbidden();
        $this->actingAs($outsider)->postJson("/api/payment-requests/{$this->pr->id}/resubmit", ['response' => 'x'])->assertForbidden();
        $this->actingAs($this->otherFinance)->postJson("/api/payment-requests/{$this->pr->id}/resubmit", ['response' => ''])->assertStatus(422);
        $this->assertSame(PaymentRequest::STATUS_QUERIED, $this->pr->refresh()->status);

        $this->actingAs($this->otherFinance)->postJson("/api/payment-requests/{$this->pr->id}/resubmit", ['response' => 'Checked.'])->assertOk();
        $this->assertSame(PaymentRequest::STATUS_IN_APPROVAL, $this->pr->refresh()->status);

        // A second query on the same request, answered by an admin.
        $this->actingAs($this->approvers[2])->postJson("/api/payment-requests/{$this->pr->id}/query", ['question' => 'One more thing?'])->assertOk();
        $this->actingAs($this->user(User::ROLE_ADMIN))->postJson("/api/payment-requests/{$this->pr->id}/resubmit", ['response' => 'Done.'])->assertOk();
        $this->assertSame(2, $this->pr->queries()->whereNotNull('resubmitted_at')->count());
    }

    public function test_the_approver_can_query_from_the_emailed_link_and_can_no_longer_reject(): void
    {
        Mail::fake();
        $token = $this->pr->approvals()->where('sequence', 1)->value('view_token');

        $this->get("/prf/view/{$this->pr->id}/{$token}")->assertOk()->assertSee('Query')->assertDontSee('btn-reject', false);

        $this->post("/prf/view/{$this->pr->id}/{$token}/reject", ['comments' => 'no'])->assertStatus(405);

        $this->post("/prf/view/{$this->pr->id}/{$token}/query", ['question' => 'Wrong cost centre?'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(PaymentRequest::STATUS_QUERIED, $this->pr->refresh()->status);
        $this->assertSame($this->approvers[1]->id, $this->pr->openQuery()->raised_by);
        Mail::assertQueuedCount(1);
        Mail::assertQueued(PaymentRequestQueryRaised::class);

        $this->get("/prf/view/{$this->pr->id}/{$token}")->assertOk()->assertSee('Wrong cost centre?');
    }

    public function test_the_submitter_can_correct_their_invoice_while_the_request_is_queried(): void
    {
        $this->raiseQueryAt(1);

        $invoice = $this->pr->invoices()->where('submitted_by', $this->submitterA->id)->first();

        $this->assertTrue($invoice->isCorrectableBySubmitter());
    }

    public function test_finance_can_release_an_invoice_from_a_queried_request_but_not_withdraw_it(): void
    {
        $this->raiseQueryAt(1);
        $invoice = $this->pr->invoices()->first();

        $this->actingAs($this->finance)->postJson("/api/payment-requests/{$this->pr->id}/withdraw", ['reason' => 'x'])->assertStatus(422);

        $this->actingAs($this->finance)->postJson("/api/invoices/{$invoice->id}/release", ['reason' => 'Duplicate'])->assertOk();

        $this->assertSame(Invoice::PAY_NOT_INITIATED, $invoice->refresh()->payment_status);
        $this->assertSame(PaymentRequest::STATUS_QUERIED, $this->pr->refresh()->status);
    }

    public function test_the_daily_approval_reminder_skips_a_queried_request(): void
    {
        $this->raiseQueryAt(1);
        $this->pr->update(['last_reminder_sent_at' => now()->subDays(3)]);

        Mail::fake();
        $this->artisan('prf:send-reminders')->assertSuccessful();

        Mail::assertNotQueued(PaymentRequestReminder::class);
    }

    public function test_an_unanswered_query_is_reminded_every_two_days_to_the_submitters_and_finance_only(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 15:00')); // Monday
        $this->raiseQueryAt(4); // the query email copies approvers; the reminder must not

        $runOn = function (string $at): int {
            $this->travelTo(Carbon::parse($at));
            Mail::fake();
            $this->artisan('prf:send-query-reminders')->assertSuccessful();

            return Mail::queued(PaymentRequestQueryRaised::class)->count();
        };

        $this->assertSame(0, $runOn('2026-10-13 09:00'), 'Tuesday: one day on, not due');
        $this->assertSame(1, $runOn('2026-10-14 09:00'), 'Wednesday: two days on');

        $reminder = Mail::queued(PaymentRequestQueryRaised::class)->sole();
        $this->assertTrue($reminder->reminder);
        $this->assertSame($this->emails([$this->submitterA, $this->submitterB, $this->finance]), $this->bccOf($reminder));

        $this->assertSame(0, $runOn('2026-10-14 18:00'), 'not twice on the same day');
        $this->assertSame(0, $runOn('2026-10-15 09:00'), 'Thursday: one day after the last reminder');
        $this->assertSame(1, $runOn('2026-10-16 09:00'), 'Friday: two days after the last reminder');

        $this->actingAs($this->submitterA)->postJson("/api/payment-requests/{$this->pr->id}/resubmit", ['response' => 'Done.'])->assertOk();

        $this->assertSame(0, $runOn('2026-10-20 09:00'), 'no reminders once resubmitted');
    }
}
