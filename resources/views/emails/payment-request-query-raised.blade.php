<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #b26a00; color: #fff; padding: 20px; border-radius: 4px 4px 0 0; }
        .body { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .footer { background-color: #eee; padding: 15px; border-radius: 0 0 4px 4px; font-size: 12px; color: #666; }
        .detail-row { margin: 8px 0; }
        .label { font-weight: bold; color: #555; }
        .amount { font-size: 18px; font-weight: bold; color: #b26a00; }
        .question { background-color: #fff4e0; border-left: 4px solid #b26a00; padding: 12px 16px; margin: 18px 0; }
        .question h3 { margin: 0 0 6px; font-size: 14px; text-transform: uppercase; color: #8a5200; }
        .question p { margin: 0; white-space: pre-line; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f0f0f0; font-size: 12px; text-transform: uppercase; }
        .btn { display: inline-block; padding: 12px 24px; background-color: #b26a00; color: #fff; text-decoration: none; border-radius: 4px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin:0;">{{ $reminder ? 'Reminder — ' : '' }}Approval Query</h2>
        </div>
        <div class="body">
            <p>Hello,</p>

            @if($reminder)
            <p>The approval query on payment request <strong>{{ $paymentRequest->reference_no }}</strong>
               has not been answered yet. The request stays on hold until it is.</p>
            @endif

            <p><strong>{{ $query->raiser?->name ?? 'An approver' }}</strong> raised an approval query at
               <strong>Stage {{ $query->sequence }}: {{ $query->stage_label }}</strong> on payment request
               <strong>{{ $paymentRequest->reference_no }}</strong>
               on {{ $query->raised_at?->format('d M Y, h:i A') }}.
               The request is on hold at that stage. It is not rejected: once the query is answered and the
               request is resubmitted, it returns to the same approver, and the approvals already given stand.</p>

            <div class="question">
                <h3>Query</h3>
                <p>{{ $query->question }}</p>
            </div>

            <p>The invoice submitter, Finance or an administrator can answer the query, make any correction
               needed, and resubmit the request in the application. Please respond there rather than by
               replying to this email.</p>

            <div class="detail-row"><span class="label">Reference:</span> {{ $paymentRequest->reference_no }}</div>
            <div class="detail-row">
                <span class="label">Total Amount:</span>
                <span class="amount">{{ \App\Support\Money::format($paymentRequest->total_amount, $paymentRequest->currency) }}</span>
            </div>

            @if($paymentRequest->invoices->isNotEmpty())
            <table>
                <thead>
                    <tr><th>Invoice</th><th>Vendor</th><th>Submitted By</th><th>Amount</th></tr>
                </thead>
                <tbody>
                    @foreach($paymentRequest->invoices as $invoice)
                    <tr>
                        <td>{{ $invoice->reference_no }}</td>
                        <td>{{ $invoice->vendor_name }}</td>
                        <td>{{ $invoice->submitter?->name ?? '—' }}</td>
                        <td>{{ \App\Support\Money::format($invoice->total_amount, $invoice->currency) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif

            <a href="{{ url('/payment-requests/'.$paymentRequest->id) }}" class="btn">Open Payment Request</a>
        </div>
        <div class="footer">
            This is an automated notification from the Invoice Payment Approval Platform.
        </div>
    </div>
</body>
</html>
