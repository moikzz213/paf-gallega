<?php

namespace App\Http\Controllers;

use App\Mail\InvoiceQueryRaised;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Vendor;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    /** Invoice Log — date-wise register, scoped by visibility. */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Invoice::query()
            ->visibleTo($user)
            ->with(['vendor:id,name,vendor_code', 'submitter:id,name,department', 'poster:id,name', 'paymentRequest:id,reference_no,status']);

        if ($request->boolean('mine')) {
            $query->where('submitted_by', $user->id);
        }

        if ($status = $request->input('status')) {
            $query->whereIn('status', is_array($status) ? $status : explode(',', $status));
        }

        if ($paymentStatus = $request->input('payment_status')) {
            $query->whereIn('payment_status', is_array($paymentStatus) ? $paymentStatus : explode(',', $paymentStatus));
        }

        if ($department = $request->input('department')) {
            $query->where('department', $department);
        }

        if ($priority = $request->input('priority')) {
            $query->whereIn('priority', is_array($priority) ? $priority : explode(',', $priority));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('submitted_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('submitted_at', '<=', $request->date('date_to'));
        }

        if ($q = trim((string) $request->input('q'))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('reference_no', 'like', "%{$q}%")
                    ->orWhere('vendor_name', 'like', "%{$q}%")
                    ->orWhere('invoice_no', 'like', "%{$q}%");
            });
        }

        $sort = in_array($request->input('sort'), ['submitted_at', 'invoice_date', 'due_date', 'total_amount', 'status', 'priority'], true)
            ? $request->input('sort') : 'submitted_at';

        return $query->orderBy($sort, $request->input('dir') === 'asc' ? 'asc' : 'desc')
            ->paginate((int) $request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $itemsData = $this->validatedItems($request);
        $data['currency'] = $this->currencyOf($itemsData);

        $invoice = DB::transaction(function () use ($request, $data, $itemsData) {
            $invoice = Invoice::create([
                ...$data,
                'reference_no' => Invoice::nextReferenceNo(),
                'status' => Invoice::STATUS_SUBMITTED,
                'payment_status' => Invoice::PAY_NOT_INITIATED,
                'submitted_by' => $request->user()->id,
                'submitted_at' => now(),
                // placeholders — recomputed from line items immediately below
                'amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
            ]);

            $this->syncItems($invoice, $itemsData);

            $invoice->update([
                'amount' => $invoice->items()->sum('amount'),
                'tax_amount' => $invoice->items()->sum('tax_amount'),
                'total_amount' => $invoice->items()->sum('total_amount'),
            ]);

            AuditLogger::log('submitted', "Invoice {$invoice->reference_no} submitted to Finance for {$invoice->vendor_name} ({$invoice->currency} {$invoice->total_amount})", $invoice);

            $this->storeDocuments($request, $invoice);

            return $invoice;
        });

        return response()->json($invoice->load('vendor:id,name,vendor_code,credit_days', 'items.customer', 'documents'), 201);
    }

    public function show(Request $request, Invoice $invoice)
    {
        $this->authorizeView($request, $invoice);

        return response()->json(
            $invoice->load([
                'submitter:id,name,email,department',
                'poster:id,name',
                'vendor:id,name,vendor_code,credit_days',
                'items.customer:id,name,customer_code',
                'documents.uploader:id,name',
                'paymentRequest.approvals.approver:id,name',
                'auditLogs.user:id,name',
            ])
        );
    }

    public function update(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if ($invoice->submitted_by !== $user->id && ! $user->isAdmin()) {
            abort(403, 'You can only edit your own invoices.');
        }

        if (! $invoice->isEditable()) {
            abort(422, 'This invoice can no longer be edited.');
        }

        $data = $this->validated($request);
        $itemsData = $this->validatedItems($request);
        $data['currency'] = $this->currencyOf($itemsData);
        $old = $invoice->only(array_keys($data));

        if ($invoice->status === Invoice::STATUS_QUERY) {
            $data['status'] = Invoice::STATUS_SUBMITTED;
            $data['finance_remarks'] = null;
        }

        $invoice->update($data);
        $this->syncItems($invoice, $itemsData);

        $invoice->update([
            'amount' => $invoice->items()->sum('amount'),
            'tax_amount' => $invoice->items()->sum('tax_amount'),
            'total_amount' => $invoice->items()->sum('total_amount'),
        ]);

        $this->storeDocuments($request, $invoice);

        AuditLogger::log('updated', "Invoice {$invoice->reference_no} updated", $invoice, $old, $data);

        return response()->json($invoice->refresh()->load('vendor:id,name,vendor_code,credit_days', 'items.customer', 'documents'));
    }

    /** Finance posts the invoice in the ERP. */
    public function post(Request $request, Invoice $invoice)
    {
        if (! in_array($invoice->status, [Invoice::STATUS_SUBMITTED, Invoice::STATUS_QUERY], true)) {
            abort(422, 'Only submitted or queried invoices can be posted.');
        }

        $data = $request->validate([
            'erp_doc_no' => ['required', 'string', 'max:100'],
            'posting_date' => ['nullable', 'date'],
        ]);

        $invoice->update([
            'status' => Invoice::STATUS_POSTED,
            'erp_doc_no' => $data['erp_doc_no'],
            'posting_date' => $data['posting_date'] ?? now()->toDateString(),
            'posted_by' => $request->user()->id,
            'posted_at' => now(),
            'finance_remarks' => null,
        ]);

        AuditLogger::log('posted', "Invoice {$invoice->reference_no} posted in ERP (doc {$invoice->erp_doc_no})", $invoice);

        return response()->json($invoice->refresh()->load('poster:id,name'));
    }

    /** Finance raises a query back to the submitting department. */
    public function raiseQuery(Request $request, Invoice $invoice)
    {
        if (! in_array($invoice->status, [Invoice::STATUS_SUBMITTED, Invoice::STATUS_POSTED], true)) {
            abort(422, 'This invoice cannot be queried in its current state.');
        }

        $data = $request->validate(['finance_remarks' => ['required', 'string', 'max:2000']]);

        $invoice->update([
            'status' => Invoice::STATUS_QUERY,
            'finance_remarks' => $data['finance_remarks'],
        ]);

        AuditLogger::log('query_raised', "Query raised on {$invoice->reference_no}: {$data['finance_remarks']}", $invoice);

        // Notify the person who submitted the invoice so they can correct and resubmit.
        $invoice->refresh()->loadMissing('submitter');
        if ($invoice->submitter?->email) {
            Mail::to($invoice->submitter->email)->send(new InvoiceQueryRaised($invoice));
        }

        return response()->json($invoice);
    }

    public function cancel(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if ($invoice->submitted_by !== $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        if ($invoice->payment_status !== Invoice::PAY_NOT_INITIATED) {
            abort(422, 'Invoices already in a payment cycle cannot be cancelled.');
        }

        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        AuditLogger::log('cancelled', "Invoice {$invoice->reference_no} cancelled", $invoice);

        return response()->json($invoice->refresh());
    }

    public function destroy(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if ($invoice->submitted_by !== $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        if ($invoice->payment_status !== Invoice::PAY_NOT_INITIATED) {
            abort(422, 'Invoices already in a payment cycle cannot be deleted.');
        }

        AuditLogger::log('deleted', "Invoice {$invoice->reference_no} deleted", $invoice);
        $invoice->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
            'invoice_no' => ['required', 'string', 'max:100'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'currency' => ['nullable', 'string', Rule::exists('currencies', 'name')->where('is_active', true)], // overwritten from the line items
            'business_unit' => ['required', 'string', Rule::exists('business_units', 'name')->where('is_active', true)],
            'department' => ['required', 'string', Rule::exists('departments', 'name')->where('is_active', true)],
            'location' => ['required', 'string', Rule::exists('locations', 'name')->where('is_active', true)],
            'payment_method' => ['required', Rule::in(array_keys(config('paf.payment_methods')))],
            'priority' => ['required', Rule::in(config('paf.priorities'))],
            'description' => ['nullable', 'string', 'max:5000'],
            'documents' => ['nullable', 'array', 'max:'.config('paf.max_documents')],
            'documents.*' => ['file', 'mimes:'.config('paf.document_mimes'), 'max:'.config('paf.max_document_kb')],
        ]);

        unset($data['documents']);

        $data['vendor_name'] = Vendor::findOrFail($data['vendor_id'])->name;

        return $data;
    }

    private function validatedItems(Request $request): array
    {
        $items = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.job_no' => ['nullable', 'string', 'max:100'],
            'items.*.customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('is_active', true)],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.currency' => ['required', 'string', Rule::exists('currencies', 'name')->where('is_active', true)],
            'items.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ]);

        foreach ($items['items'] as &$item) {
            $item['tax_amount'] = $item['tax_amount'] ?? 0;
            $item['total_amount'] = round($item['amount'] + $item['tax_amount'], 2);
        }
        unset($item);

        // The invoice header totals are plain sums of the lines, so a single invoice can only be
        // in one currency — otherwise the header amount would be a meaningless mixed figure.
        if (count(array_unique(array_column($items['items'], 'currency'))) > 1) {
            throw ValidationException::withMessages([
                'items' => 'All line items on an invoice must use the same currency.',
            ]);
        }

        return $items['items'];
    }

    /** The invoice's currency is the one picked on its lines — never whatever the form posted. */
    private function currencyOf(array $itemsData): string
    {
        return $itemsData[0]['currency'];
    }

    private function syncItems(Invoice $invoice, array $itemsData): void
    {
        $invoice->items()->delete();

        foreach ($itemsData as $index => $item) {
            $invoice->items()->create([
                'sort_order' => $index,
                'job_no' => $item['job_no'] ?? null,
                'customer_id' => $item['customer_id'] ?? null,
                'description' => $item['description'] ?? null,
                'currency' => $item['currency'],
                'amount' => $item['amount'],
                'tax_amount' => $item['tax_amount'],
                'total_amount' => $item['total_amount'],
            ]);
        }
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
            || ($user->isApprover() && $invoice->paymentRequest
                && $invoice->paymentRequest->approvals()->where('approver_id', $user->id)->exists());

        abort_unless($visible, 403, 'You do not have access to this invoice.');
    }
}
