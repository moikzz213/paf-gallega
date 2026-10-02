<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #1a237e; color: #fff; padding: 20px; border-radius: 4px 4px 0 0; }
        .body { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .footer { background-color: #eee; padding: 15px; border-radius: 0 0 4px 4px; font-size: 12px; color: #666; }
        .detail-row { margin: 8px 0; }
        .label { font-weight: bold; color: #555; }
        .note { background:#eef2ff; border:1px solid #c7d2fe; padding:12px; border-radius:4px; margin:12px 0; }
        .btn { display: inline-block; padding: 12px 24px; background-color: #1a237e; color: #fff; text-decoration: none; border-radius: 4px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin:0;">Invoice Corrected During Approval</h2>
        </div>
        <div class="body">
            <p>Hello {{ $paymentRequest->creator?->name }},</p>

            <p>{{ $corrector->name }} corrected an invoice they submitted while your payment request
               {{ $paymentRequest->reference_no }} is in approval. The approvals given so far stand, and the
               request stays with its current approver.</p>

            <div class="detail-row"><span class="label">Payment request:</span> {{ $paymentRequest->reference_no }}</div>
            <div class="detail-row"><span class="label">Invoice:</span> {{ $invoice->reference_no }} &middot; {{ $invoice->vendor_name }} &middot; {{ $invoice->invoice_no }}</div>
            <div class="detail-row">
                <span class="label">Total:</span>
                {{ \App\Support\Money::format($previousTotal, $invoice->currency) }}
                @if(round($previousTotal, 2) !== round((float) $invoice->total_amount, 2))
                    &rarr; {{ \App\Support\Money::format($invoice->total_amount, $invoice->currency) }}
                @else
                    (unchanged)
                @endif
            </div>
            <div class="detail-row"><span class="label">Changed:</span> {{ $changed ? implode(', ', $changed) : 'no field values (documents only)' }}</div>
            @if($invoice->erp_doc_no)
            <div class="detail-row"><span class="label">ERP document:</span> {{ $invoice->erp_doc_no }}</div>
            @endif

            <div class="note">
                @if($invoice->erp_doc_no)
                    This invoice is posted to the ERP as {{ $invoice->erp_doc_no }}. Please check that the ERP entry
                    still matches the corrected invoice.
                @else
                    Please review the correction before the request completes approval.
                @endif
            </div>

            <a href="{{ url('/invoices/'.$invoice->id) }}" class="btn">Open Invoice</a>
        </div>
        <div class="footer">
            This is an automated notification from the Invoice Payment Approval Platform.
        </div>
    </div>
</body>
</html>
