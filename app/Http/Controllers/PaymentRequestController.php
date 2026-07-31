<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PaymentRequestService;
use App\Services\PdfMergeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentRequestController extends Controller
{
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
            ->with(['creator:id,name', 'invoices:id,payment_request_id,reference_no,vendor_name,total_amount', 'approvals.approver:id,name']);

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

        abort_unless(
            in_array($paymentRequest->status, [PaymentRequest::STATUS_APPROVED, PaymentRequest::STATUS_PAID], true),
            422, 'PDF is available only after the request is fully approved.'
        );

        $paymentRequest->load([
            'creator:id,name', 'payer:id,name',
            'invoices.submitter:id,name', 'invoices.poster:id,name', 'invoices.documents',
            'invoices.items',
            'approvals.approver:id,name', 'approvals.approvalLevel:level,min_amount',
        ]);

        // Supplier code comes from the vendor master, keyed by the invoice's vendor name.
        $supplierCodes = Vendor::whereIn('name', $paymentRequest->invoices->pluck('vendor_name')->filter()->unique())
            ->pluck('vendor_code', 'name');

        // Every active approval level, so the PDF can show reached (required) and
        // not-reached (still displayed) approvers regardless of this PRF's amount.
        $approvalLevels = ApprovalLevel::where('is_active', true)
            ->with('defaultApprover:id,name')
            ->orderBy('level')
            ->get();

        $pdf = Pdf::loadView('pdf.payment-request', [
            'paymentRequest' => $paymentRequest,
            'supplierCodes' => $supplierCodes,
            'approvalLevels' => $approvalLevels,
        ])
            ->setPaper('a4', 'landscape')
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true);

        $mainPdfContent = $pdf->output();

        // PDFs and images are rendered into the document; everything else (Excel, Word, ...)
        // can only be offered as a download link on a trailing page.
        $attachments = [];
        $links = [];
        foreach ($paymentRequest->invoices as $invoice) {
            foreach ($invoice->documents as $document) {
                $path = storage_path('app/private/'.$document->file_path);
                if (! is_file($path)) {
                    continue;
                }

                $entry = [
                    'name' => $document->original_name,
                    'url' => url("/api/documents/{$document->id}/download"),
                    'mime_type' => $document->mime_type,
                    'size' => $document->size,
                    'group' => "{$invoice->reference_no} - {$invoice->vendor_name}",
                ];

                if (in_array($document->mime_type, PdfMergeService::MERGEABLE_MIMES, true)) {
                    // Carries the link fields too: a PDF that turns out to be encrypted or
                    // damaged falls back to the download list instead of failing the export.
                    $attachments[] = $entry + ['path' => $path];
                } else {
                    $links[] = $entry;
                }
            }
        }

        if ($attachments || $links) {
            $merged = app(PdfMergeService::class)->mergePdfs($mainPdfContent, $attachments, $links);

            return response($merged, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "attachment; filename=\"{$paymentRequest->reference_no}.pdf\"");
        }

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
