<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaymentRequestService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentRequestController extends Controller
{
    public function __construct(private PaymentRequestService $service) {}

    /** Invoices eligible to be pulled into a new payment request (Finance only). */
    public function eligible(Request $request)
    {
        return Invoice::query()
            ->where('payment_status', Invoice::PAY_NOT_INITIATED)
            ->whereIn('status', [Invoice::STATUS_POSTED, Invoice::STATUS_SUBMITTED])
            ->with(['submitter:id,name,department'])
            ->orderByDesc('submitted_at')
            ->paginate((int) $request->input('per_page', 100));
    }

    public function index(Request $request)
    {
        $query = PaymentRequest::query()
            ->visibleTo($request->user())
            ->with(['creator:id,name', 'invoices:id,payment_request_id,reference_no,vendor_name,total_amount', 'approvals.approver:id,name']);

        if ($status = $request->input('status')) {
            $query->whereIn('status', is_array($status) ? $status : explode(',', $status));
        }

        if ($q = trim((string) $request->input('q'))) {
            $query->where('reference_no', 'like', "%{$q}%");
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
            'creator:id,name', 'payer:id,name',
            'invoices.submitter:id,name',
            'approvals.approver:id,name',
            'auditLogs.user:id,name',
        ]));
    }

    public function store(Request $request)
    {
        $data = $this->validatedChain($request);

        $invoices = Invoice::whereIn('id', $data['invoice_ids'])->get();
        $total = (float) $invoices->sum('total_amount');

        $stages = $this->buildStages($total, $data['approvers'] ?? [], $data['adhoc_approvers'] ?? []);

        $pr = $this->service->create($data['invoice_ids'], $request->user(), $stages);

        return response()->json($pr->load('invoices', 'approvals.approver:id,name'), 201);
    }

    /** Approver queue — payment requests awaiting the current user's stage. */
    public function pending(Request $request)
    {
        $user = $request->user();

        $query = PaymentRequest::query()
            ->where('status', PaymentRequest::STATUS_IN_APPROVAL)
            ->with(['creator:id,name', 'invoices:id,payment_request_id,reference_no,vendor_name,total_amount', 'approvals.approver:id,name']);

        if (! $user->isAdmin()) {
            abort_unless($user->isApprover(), 403, 'Only approvers have an approval queue.');
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

    public function markPaid(Request $request, PaymentRequest $paymentRequest)
    {
        $data = $request->validate(['payment_reference' => ['required', 'string', 'max:100']]);
        $pr = $this->service->markPaid($paymentRequest, $request->user(), $data['payment_reference']);

        return response()->json($pr->load('invoices'));
    }

    public function downloadPdf(Request $request, PaymentRequest $paymentRequest)
    {
        abort_unless(
            PaymentRequest::whereKey($paymentRequest->id)->visibleTo($request->user())->exists(),
            403, 'You do not have access to this payment request.'
        );

        $paymentRequest->load([
            'creator:id,name', 'payer:id,name',
            'invoices.submitter:id,name', 'invoices.documents',
            'approvals.approver:id,name',
        ]);

        $pdf = Pdf::loadView('pdf.payment-request', ['paymentRequest' => $paymentRequest])
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true);

        return $pdf->download("{$paymentRequest->reference_no}.pdf");
    }

    /** Turn the client's level/ad-hoc assignments into an ordered chain. */
    private function buildStages(float $total, array $assignments, array $adhoc): array
    {
        $stages = [];

        foreach (ApprovalLevel::requiredFor($total) as $level) {
            $approverId = $assignments[$level->level] ?? $level->default_approver_id;
            if (! $approverId) {
                continue;
            }
            $stages[] = [
                'approver_id' => (int) $approverId,
                'label' => $level->name,
                'level' => $level->level,
                'is_adhoc' => false,
            ];
        }

        foreach ($adhoc as $stage) {
            if (! empty($stage['approver_id'])) {
                $stages[] = [
                    'approver_id' => (int) $stage['approver_id'],
                    'label' => trim((string) ($stage['label'] ?? '')) ?: 'Additional approver',
                    'level' => null,
                    'is_adhoc' => true,
                ];
            }
        }

        return $stages;
    }

    private function validatedChain(Request $request): array
    {
        $isApprover = Rule::exists('users', 'id')->where(fn ($q) => $q
            ->where('is_active', true)
            ->whereIn('role', [User::ROLE_APPROVER, User::ROLE_ADMIN]));

        return $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['integer', 'exists:invoices,id'],
            'approvers' => ['nullable', 'array'],
            'approvers.*' => ['nullable', 'integer', $isApprover],
            'adhoc_approvers' => ['nullable', 'array', 'max:10'],
            'adhoc_approvers.*.approver_id' => ['required', 'integer', $isApprover],
            'adhoc_approvers.*.label' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
