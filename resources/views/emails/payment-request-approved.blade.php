<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #047857; color: #fff; padding: 20px; border-radius: 4px 4px 0 0; }
        .body { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .footer { background-color: #eee; padding: 15px; border-radius: 0 0 4px 4px; font-size: 12px; color: #666; }
        .detail-row { margin: 8px 0; }
        .label { font-weight: bold; color: #555; }
        .amount { font-size: 18px; font-weight: bold; color: #047857; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f0f0f0; font-size: 12px; text-transform: uppercase; }
        .btn { display: inline-block; padding: 12px 24px; background-color: #047857; color: #fff; text-decoration: none; border-radius: 4px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin:0;">Payment Request Fully Approved</h2>
        </div>
        <div class="body">
            <p>Hello,</p>

            <p>Your payment request has completed all approval stages and has been
               <strong>released for payment</strong>.</p>

            <div class="detail-row"><span class="label">Reference:</span> {{ $paymentRequest->reference_no }}</div>
            <div class="detail-row">
                <span class="label">Total Amount:</span>
                <span class="amount">{{ number_format($paymentRequest->total_amount, 2) }}</span>
            </div>
            <div class="detail-row">
                <span class="label">Approved on:</span>
                {{ optional($paymentRequest->approved_at)->format('d M Y, h:i A') }}
            </div>

            @if($paymentRequest->invoices->count())
            <h3>Invoices Included</h3>
            <table>
                <thead>
                    <tr><th>Reference</th><th>Vendor</th><th>Amount</th></tr>
                </thead>
                <tbody>
                    @foreach($paymentRequest->invoices as $invoice)
                    <tr>
                        <td>{{ $invoice->reference_no }}</td>
                        <td>{{ $invoice->vendor_name }}</td>
                        <td>{{ number_format($invoice->total_amount, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif

            <a href="{{ route('payment-request.public', ['id' => $paymentRequest->id, 'token' => $paymentRequest->view_token]) }}" class="btn">View Payment Request</a>
        </div>
        <div class="footer">
            This is an automated notification from the Invoice Payment Approval Platform.
        </div>
    </div>
</body>
</html>
