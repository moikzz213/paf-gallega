<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PafDocumentService;
use App\Services\PaymentRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentRequestController extends Controller
{
    /**
     * The standing level the optional L2-A approver sits behind. L2-A is a second signature at the
     * second tier, so it is only offered — and only accepted — when this level is in the chain.
     */
    private const L2A_AFTER_LEVEL = 2;

    public function __construct(private PaymentRequestService $service) {}

    /** Invoices eligible to be pulled into a new payment request (Finance only). */
    public function eligible(Request $request)
    {
        $query = Invoice::query()
            ->where('payment_status', Invoice::PAY_NOT_INITIATED)
            ->whereIn('status', [Invoice::STATUS_POSTED, Invoice::STATUS_SUBMITTED])
            ->with(['submitter:id,name,department', 'items:id,invoice_id,job_no,customer_id', 'items.customer:id,name']);

        if ($department = $request->input('department')) {
            $query->where('department', $department);
        }

        if ($currency = $request->input('currency')) {
            $query->where('currency', $currency);
        }

        if ($vendor = trim((string) $request->input('vendor'))) {
            $query->where('vendor_name', 'like', "%{$vendor}%");
        }

        if ($invoiceNo = trim((string) $request->input('invoice_no'))) {
            $query->where('invoice_no', 'like', "%{$invoiceNo}%");
        }

        if ($jobNo = trim((string) $request->input('job_no'))) {
            $query->whereHas('items', fn ($iq) => $iq->where('job_no', 'like', "%{$jobNo}%"));
        }

        if ($customer = trim((string) $request->input('customer'))) {
            $query->whereHas('items.customer', fn ($cq) => $cq->where('name', 'like', "%{$customer}%"));
        }

        return $query->orderByDesc('submitted_at')->paginate((int) $request->input('per_page', 100));
    }

    public function index(Request $request)
    {
        $query = PaymentRequest::query()
            ->visibleTo($request->user())
            ->with(['creator:id,name', 'invoices:id,payment_request_id,reference_no,vendor_name,total_amount,currency', 'approvals.approver:id,name']);

        if ($status = $request->input('status')) {
            $query->whereIn('status', is_array($status) ? $status : explode(',', $status));
        }

        if ($department = $request->input('department')) {
            $query->whereHas('invoices', fn ($iq) => $iq->where('department', $department));
        }

        if ($vendor = trim((string) $request->input('vendor'))) {
            $query->whereHas('invoices', fn ($iq) => $iq->where('vendor_name', 'like', "%{$vendor}%"));
        }

        if ($q = trim((string) $request->input('q'))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('reference_no', 'like', "%{$q}%")
                    ->orWhereHas('invoices', fn ($iq) => $iq
                        ->where('vendor_name', 'like', "%{$q}%")
                        ->orWhere('invoice_no', 'like', "%{$q}%"));
            });
        }

        return $query->latest()->paginate((int) $request->input('per_page', 15));
    }

    public function show(Request $request, PaymentRequest $paymentRequest)
    {
        abort_unless(
            PaymentRequest::whereKey($paymentRequest->id)->visibleTo($request->user())->exists(),
            403, 'You do not have access to this payment request.'
        );

        return response()->json($paymentRequest->load([
            'creator:id,name', 'payer:id,name', 'withdrawer:id,name',
            'invoices.submitter:id,name',
            'invoices.items.customer:id,name',
            'approvals.approver:id,name',
            'auditLogs.user:id,name',
        ]));
    }

    public function store(Request $request)
    {
        $data = $this->validatedChain($request);

        $invoices = Invoice::whereIn('id', $data['invoice_ids'])->get();
        $creditIds = $data['credit_invoice_ids'] ?? [];
        // Corrects the sign of the invoices Finance marked as credit notes, in memory only, so both
        // checks below and the chain are measured on what the request will actually ask for. The
        // same flip is persisted inside `create`'s transaction.
        PaymentRequestService::applyCreditMarks($invoices, $creditIds);
        // Ahead of the chain, which converts each invoice into the base currency: a mixed set has no
        // single currency to report on and should be refused as mixed, not as an unrated currency.
        PaymentRequestService::assertSingleCurrency($invoices);
        // Ahead of it for the same reason: a set that nets to zero or below requires no approval
        // level, so the chain builder would report it as a missing approver rather than as a
        // selection that pays nothing.
        PaymentRequestService::assertPayableTotal($invoices);

        $stages = $this->buildStages(
            $invoices,
            $data['approvers'] ?? [],
            $data['adhoc_approvers'] ?? [],
            ! empty($data['l2a_approver_id']) ? (int) $data['l2a_approver_id'] : null,
        );

        $pr = $this->service->create($data['invoice_ids'], $request->user(), $stages, $creditIds);

        return response()->json($pr->load('invoices', 'approvals.approver:id,name'), 201);
    }

    /** Approver queue — payment requests awaiting the current user's stage. */
    public function pending(Request $request)
    {
        $user = $request->user();

        $query = PaymentRequest::query()
            ->where('status', PaymentRequest::STATUS_IN_APPROVAL)
            ->with([
                'creator:id,name',
                'invoices:id,payment_request_id,reference_no,vendor_name,invoice_no,total_amount,currency,description,submitted_by',
                'invoices.submitter:id,name',
                'invoices.items.customer:id,name',
                'approvals.approver:id,name',
            ]);

        if (! $user->isAdmin()) {
            // Finance users nominated as approvers get a queue too, scoped the same way.
            abort_unless($user->canApprove(), 403, 'Only approvers have an approval queue.');
            $query->whereHas('approvals', fn ($a) => $a
                ->whereColumn('sequence', 'payment_requests.current_stage')
                ->where('approver_id', $user->id));
        }

        return $query->orderBy('sent_at')->paginate((int) $request->input('per_page', 15));
    }

    public function approve(Request $request, PaymentRequest $paymentRequest)
    {
        $data = $request->validate(['comments' => ['nullable', 'string', 'max:2000']]);
        $pr = $this->service->approve($paymentRequest, $request->user(), $data['comments'] ?? null);

        return response()->json($pr->load('approvals.approver:id,name'));
    }

    public function reject(Request $request, PaymentRequest $paymentRequest)
    {
        $data = $request->validate(['comments' => ['required', 'string', 'max:2000']]);
        $pr = $this->service->reject($paymentRequest, $request->user(), $data['comments']);

        return response()->json($pr->load('approvals.approver:id,name'));
    }

    /**
     * Withdraw an approved-but-unpaid request. Finance/admin only (enforced on the route as well) —
     * this reverses a completed approval, so it is not an approver action.
     */
    public function withdraw(Request $request, PaymentRequest $paymentRequest)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $pr = $this->service->withdraw($paymentRequest, $request->user(), $data['reason']);

        return response()->json($pr->load('invoices', 'withdrawer:id,name', 'approvals.approver:id,name'));
    }

    public function markPaid(Request $request, PaymentRequest $paymentRequest)
    {
        $data = $request->validate(['payment_reference' => ['required', 'string', 'max:100']]);
        $pr = $this->service->markPaid($paymentRequest, $request->user(), $data['payment_reference']);

        return response()->json($pr->load('invoices'));
    }

    /**
     * Correct a mistyped payment reference. Restricted to the user who recorded the payment, or an
     * admin — enforced in the service, which owns the rest of the payment lifecycle rules.
     */
    public function updatePaymentReference(Request $request, PaymentRequest $paymentRequest)
    {
        $data = $request->validate(['payment_reference' => ['required', 'string', 'max:100']]);
        $pr = $this->service->updatePaymentReference($paymentRequest, $request->user(), $data['payment_reference']);

        return response()->json($pr->load('payer:id,name'));
    }

    public function downloadPdf(Request $request, PaymentRequest $paymentRequest, PafDocumentService $paf)
    {
        abort_unless(
            PaymentRequest::whereKey($paymentRequest->id)->visibleTo($request->user())->exists(),
            403, 'You do not have access to this payment request.'
        );

        // Available from creation onwards, not only once the request is fully approved: the PAF is
        // the document the approval chain is meant to be approving, so it has to exist while that
        // chain is still running. A document produced before the last signature is watermarked as
        // not yet approved by the view, so a draft cannot be mistaken for an authorisation.
        return response($paf->render($paymentRequest), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$paf->filename($paymentRequest).'"');
    }

    /**
     * Turn the client's level/ad-hoc assignments into an ordered chain.
     *
     * A stage's `label` is the job description shown against the approver everywhere the chain is
     * rendered — screen, PDF and the public link. It is the **approver's own job title**, because a
     * level's name describes a generic position ("Department Manager") that is often not the position
     * of the person actually signing: Finance group members now approve at L1/L2. The level name
     * remains the fallback for a user with no job title on record. Snapshotted at creation, like the
     * rest of the stage, so a later job change cannot rewrite an approval that already happened.
     *
     * Which levels apply is decided on the invoices' worth in the base currency, not on their face
     * value: the thresholds are AED figures, so a USD 36,000 request has to be measured as the
     * ~AED 132,000 it is worth or it routes below the tier that should sign it.
     */
    private function buildStages($invoices, array $assignments, array $adhoc, ?int $l2aApproverId = null): array
    {
        $levels = ApprovalLevel::requiredForInvoices($invoices);

        $approverIds = collect($levels)
            ->map(fn ($level) => $assignments[$level->level] ?? $level->default_approver_id)
            ->concat(collect($adhoc)->pluck('approver_id'))
            ->push($l2aApproverId)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        $jobTitles = User::whereIn('id', $approverIds)->pluck('job_title', 'id');
        $jobTitle = fn (int $id) => trim((string) ($jobTitles[$id] ?? '')) ?: null;

        $stages = [];

        foreach ($levels as $level) {
            $approverId = $assignments[$level->level] ?? $level->default_approver_id;
            if (! $approverId) {
                continue;
            }
            $this->assertApproverBelongsToLevel($level, (int) $approverId);
            $stages[] = [
                'approver_id' => (int) $approverId,
                'label' => $jobTitle((int) $approverId) ?? $level->name,
                'level' => $level->level,
                'is_adhoc' => false,
            ];

            // L2-A: the optional second signature at level 2, routed straight after L2 rather than
            // appended behind the whole chain the way a general ad-hoc stage is. It carries no
            // `level` of its own — the PDF keys its required approvers by level, so a second stage
            // claiming level 2 would displace the real one — and is marked ad-hoc like any other
            // stage that is not one of the standing tiers.
            if ($l2aApproverId && (int) $level->level === self::L2A_AFTER_LEVEL) {
                $stages[] = [
                    'approver_id' => $l2aApproverId,
                    'label' => 'L2-A'.($jobTitle($l2aApproverId) ? ' — '.$jobTitle($l2aApproverId) : ' Additional Approver'),
                    'level' => null,
                    'is_adhoc' => true,
                ];
            }
        }

        // A silently dropped approver is worse than a refused request: whoever added L2-A believes
        // that person will sign, and would never find out that the chain skipped them.
        if ($l2aApproverId) {
            $l2aStages = array_values(array_filter($stages, fn ($s) => $s['approver_id'] === $l2aApproverId));

            if (! $l2aStages) {
                throw ValidationException::withMessages([
                    'l2a_approver_id' => 'The additional L2-A approver applies only when level 2 is part of the chain — this request does not reach that level.',
                ]);
            }

            if (count($l2aStages) > 1) {
                throw ValidationException::withMessages([
                    'l2a_approver_id' => 'The L2-A approver is already on this chain — an approver cannot hold two stages on the same request.',
                ]);
            }
        }

        foreach ($adhoc as $stage) {
            if (! empty($stage['approver_id'])) {
                $approverId = (int) $stage['approver_id'];
                $stages[] = [
                    'approver_id' => $approverId,
                    // An ad-hoc stage's label is typed by whoever adds it; fall back to the
                    // approver's job title rather than a generic placeholder.
                    'label' => trim((string) ($stage['label'] ?? '')) ?: ($jobTitle($approverId) ?? 'Additional approver'),
                    'level' => null,
                    'is_adhoc' => true,
                ];
            }
        }

        return $stages;
    }

    /**
     * A level may only be filled by a user carrying that approval level — the chain builder hides
     * everyone else, and this keeps a hand-crafted request from routing to the wrong tier. The
     * level's own default approver stays allowed so an admin's configured default never 422s.
     */
    private function assertApproverBelongsToLevel(ApprovalLevel $level, int $approverId): void
    {
        if ($approverId === $level->default_approver_id) {
            return;
        }

        $belongs = User::whereKey($approverId)->where('approval_level', $level->level)->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                "approvers.{$level->level}" => "The approver chosen for level {$level->level} ({$level->name}) is not assigned to that approval level.",
            ]);
        }
    }

    private function validatedChain(Request $request): array
    {
        // Same rule as User::canApprove(), as a database constraint.
        $isApprover = Rule::exists('users', 'id')->where(fn ($q) => $q
            ->where('is_active', true)
            ->where(fn ($eligible) => $eligible
                ->whereIn('role', [User::ROLE_APPROVER, User::ROLE_ADMIN])
                ->orWhere(fn ($finance) => $finance
                    ->where('role', User::ROLE_FINANCE)
                    ->whereNotNull('approval_level'))));

        return $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['integer', 'exists:invoices,id'],
            // Invoices in this selection that are really credit notes recorded as a positive amount.
            // Marking one corrects its sign so the request deducts it — see
            // PaymentRequestService::applyCreditMarks, which owns the rules.
            'credit_invoice_ids' => ['nullable', 'array'],
            'credit_invoice_ids.*' => ['integer', 'exists:invoices,id'],
            'approvers' => ['nullable', 'array'],
            'approvers.*' => ['nullable', 'integer', $isApprover],
            'adhoc_approvers' => ['nullable', 'array', 'max:10'],
            'adhoc_approvers.*.approver_id' => ['required', 'integer', $isApprover],
            'adhoc_approvers.*.label' => ['nullable', 'string', 'max:100'],
            // The optional second signature at level 2 — see buildStages for where it lands in the
            // chain and the two ways it can be refused.
            'l2a_approver_id' => ['nullable', 'integer', $isApprover],
        ]);
    }
}
