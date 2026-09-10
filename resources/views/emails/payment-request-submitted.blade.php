<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background-color: #1a237e; color: #fff; padding: 20px; border-radius: 4px 4px 0 0; }
        .body { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .footer { background-color: #eee; padding: 15px; border-radius: 0 0 4px 4px; font-size: 12px; color: #666; }
        .detail-row { margin: 8px 0; }
        .label { font-weight: bold; color: #555; }
        .amount { font-size: 18px; font-weight: bold; color: #1a237e; }
        .btn { display: inline-block; padding: 12px 24px; background-color: #1a237e; color: #fff; text-decoration: none; border-radius: 4px; margin-top: 15px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f0f0f0; font-size: 12px; text-transform: uppercase; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin:0;">Payment Request — Approval Required</h2>
        </div>
        <div class="body">
            <p>Hello {{ $paymentRequest->currentApproval()?->approver?->name }},</p>

            <p>A new payment request has been submitted and is now awaiting your approval at
               <strong>{{ $paymentRequest->currentApproval()?->label }}</strong>.</p>

            <div class="detail-row">
                <span class="label">Reference:</span> {{ $paymentRequest->reference_no }}
            </div>
            <div class="detail-row">
                <span class="label">Total Amount:</span>
                <span class="amount">{{ \App\Support\Money::format($paymentRequest->total_amount, $paymentRequest->currency) }}</span>
            </div>
            <div class="detail-row">
                <span class="label">Submitted by:</span> {{ $paymentRequest->creator?->name }}
            </div>
            <div class="detail-row">
                <span class="label">Date:</span> {{ $paymentRequest->sent_at->format('d M Y, h:i A') }}
            </div>

            @include('emails.partials.payment-request-invoice-lines')

            <p>Please review and take action on this payment request through the PAF system.</p>

            <a href="{{ route('payment-request.public', ['id' => $paymentRequest->id, 'token' => $paymentRequest->currentApproval()?->view_token]) }}" class="btn">Review & Approve</a>
        </div>
        <div class="footer">
            This is an automated notification from the Invoice Payment Approval Platform.
        </div>
    </div>
</body>
</html>
