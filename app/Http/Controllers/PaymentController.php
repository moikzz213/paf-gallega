<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /** Finance processing queue: fully-approved and scheduled payments. */
    public function queue(Request $request)
    {
        $status = $request->input('status', Invoice::STATUS_APPROVED);

        abort_unless(in_array($status, [Invoice::STATUS_APPROVED, Invoice::STATUS_SCHEDULED, Invoice::STATUS_PAID], true), 422);

        return Invoice::query()
            ->where('status', $status)
            ->with(['submitter:id,name,department', 'payer:id,name'])
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy($status === Invoice::STATUS_PAID ? 'paid_at' : 'approved_at', $status === Invoice::STATUS_PAID ? 'desc' : 'asc')
            ->paginate((int) $request->input('per_page', 15));
    }

    public function schedule(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->status === Invoice::STATUS_APPROVED, 422, 'Only approved requests can be scheduled.');

        $data = $request->validate([
            'scheduled_date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $invoice->update([
            'status' => Invoice::STATUS_SCHEDULED,
            'scheduled_date' => $data['scheduled_date'],
        ]);

        AuditLogger::log('scheduled', "Payment for {$invoice->reference_no} scheduled on {$data['scheduled_date']}", $invoice);

        return response()->json($invoice->refresh());
    }

    public function markPaid(Request $request, Invoice $invoice)
    {
        abort_unless(
            in_array($invoice->status, [Invoice::STATUS_APPROVED, Invoice::STATUS_SCHEDULED], true),
            422,
            'Only approved or scheduled requests can be marked as paid.'
        );

        $data = $request->validate([
            'payment_reference' => ['required', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $invoice->update([
            'status' => Invoice::STATUS_PAID,
            'payment_reference' => $data['payment_reference'],
            'paid_at' => $data['paid_at'] ?? now(),
            'paid_by' => $request->user()->id,
        ]);

        AuditLogger::log('paid', "Payment for {$invoice->reference_no} completed (ref: {$data['payment_reference']})", $invoice);

        return response()->json($invoice->refresh());
    }
}
