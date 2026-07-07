<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\ApprovalService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(private ApprovalService $approvals)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Invoice::query()
            ->visibleTo($user)
            ->with(['submitter:id,name,department']);

        if ($request->boolean('mine')) {
            $query->where('submitted_by', $user->id);
        }

        if ($status = $request->input('status')) {
            $query->whereIn('status', is_array($status) ? $status : explode(',', $status));
        }

        if ($department = $request->input('department')) {
            $query->where('department', $department);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('invoice_date', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('invoice_date', '<=', $request->date('date_to'));
        }

        if ($q = trim((string) $request->input('q'))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('reference_no', 'like', "%{$q}%")
                    ->orWhere('vendor_name', 'like', "%{$q}%")
                    ->orWhere('invoice_no', 'like', "%{$q}%");
            });
        }

        $sort = in_array($request->input('sort'), ['created_at', 'invoice_date', 'due_date', 'total_amount', 'status'], true)
            ? $request->input('sort') : 'created_at';

        return $query->orderBy($sort, $request->input('dir') === 'asc' ? 'asc' : 'desc')
            ->paginate((int) $request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $invoice = DB::transaction(function () use ($request, $data) {
            $invoice = Invoice::create([
                ...$data,
                'reference_no' => Invoice::nextReferenceNo(),
                'status' => Invoice::STATUS_DRAFT,
                'submitted_by' => $request->user()->id,
            ]);

            AuditLogger::log('created', "Request {$invoice->reference_no} created for {$invoice->vendor_name} ({$invoice->currency} {$invoice->total_amount})", $invoice);

            $this->storeDocuments($request, $invoice);

            return $invoice;
        });

        if ($request->input('action') === 'submit') {
            $this->approvals->submit($invoice);
        }

        return response()->json($invoice->load('documents', 'approvals'), 201);
    }

    public function show(Request $request, Invoice $invoice)
    {
        $this->authorizeView($request, $invoice);

        return response()->json(
            $invoice->load([
                'submitter:id,name,email,department',
                'payer:id,name',
                'documents.uploader:id,name',
                'approvals.approver:id,name',
                'auditLogs.user:id,name',
            ])
        );
    }

    public function update(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if ($invoice->submitted_by !== $user->id && ! $user->isAdmin()) {
            abort(403, 'You can only edit your own requests.');
        }

        if (! $invoice->isEditable()) {
            abort(422, 'Only draft or rejected requests can be edited.');
        }

        $data = $this->validated($request);
        $old = $invoice->only(array_keys($data));

        $invoice->update($data);
        $this->storeDocuments($request, $invoice);

        AuditLogger::log('updated', "Request {$invoice->reference_no} updated", $invoice, $old, $data);

        if ($request->input('action') === 'submit') {
            $this->approvals->submit($invoice);
        }

        return response()->json($invoice->refresh()->load('documents', 'approvals'));
    }

    public function destroy(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if ($invoice->submitted_by !== $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            abort(422, 'Only drafts can be deleted.');
        }

        AuditLogger::log('deleted', "Draft {$invoice->reference_no} deleted");
        $invoice->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function submit(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if ($invoice->submitted_by !== $user->id && ! $user->isAdmin()) {
            abort(403, 'You can only submit your own requests.');
        }

        return response()->json($this->approvals->submit($invoice)->load('approvals'));
    }

    public function cancel(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if ($invoice->submitted_by !== $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        if (! in_array($invoice->status, [Invoice::STATUS_DRAFT, Invoice::STATUS_PENDING], true)) {
            abort(422, 'Only draft or pending requests can be cancelled.');
        }

        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'current_level' => null]);
        AuditLogger::log('cancelled', "Request {$invoice->reference_no} cancelled", $invoice);

        return response()->json($invoice->refresh());
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'vendor_name' => ['required', 'string', 'max:255'],
            'vendor_email' => ['nullable', 'email', 'max:255'],
            'vendor_trn' => ['nullable', 'string', 'max:50'],
            'invoice_no' => ['required', 'string', 'max:100'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'currency' => ['required', Rule::in(config('paf.currencies'))],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999'],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'category' => ['required', Rule::in(config('paf.categories'))],
            'department' => ['required', Rule::in(config('paf.departments'))],
            'cost_center' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['required', Rule::in(array_keys(config('paf.payment_methods')))],
            'priority' => ['required', Rule::in(config('paf.priorities'))],
            'description' => ['nullable', 'string', 'max:5000'],
            'documents' => ['nullable', 'array', 'max:'.config('paf.max_documents')],
            'documents.*' => ['file', 'mimes:'.config('paf.document_mimes'), 'max:'.config('paf.max_document_kb')],
        ]);

        $data['tax_amount'] = $data['tax_amount'] ?? 0;
        $data['total_amount'] = round($data['amount'] + $data['tax_amount'], 2);
        unset($data['documents']);

        return $data;
    }

    private function storeDocuments(Request $request, Invoice $invoice): void
    {
        foreach ($request->file('documents', []) as $file) {
            $path = $file->store("invoices/{$invoice->id}", 'local');

            $invoice->documents()->create([
                'uploaded_by' => $request->user()->id,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);

            AuditLogger::log('document_uploaded', "Document '{$file->getClientOriginalName()}' attached to {$invoice->reference_no}", $invoice);
        }
    }

    private function authorizeView(Request $request, Invoice $invoice): void
    {
        $user = $request->user();

        $visible = $user->canViewAllInvoices()
            || $invoice->submitted_by === $user->id
            || ($user->isApprover() && $invoice->approvals()->where('level', $user->approval_level)->exists());

        abort_unless($visible, 403, 'You do not have access to this request.');
    }
}
