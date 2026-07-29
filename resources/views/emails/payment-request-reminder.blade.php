<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #e65100; color: #fff; padding: 20px; border-radius: 4px 4px 0 0; }
        .body { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .footer { background-color: #eee; padding: 15px; border-radius: 0 0 4px 4px; font-size: 12px; color: #666; }
        .detail-row { margin: 8px 0; }
        .label { font-weight: bold; color: #555; }
        .amount { font-size: 18px; font-weight: bold; color: #e65100; }
        .pending-days { background-color: #fff3e0; padding: 10px; border-left: 4px solid #e65100; margin: 15px 0; }
        .btn { display: inline-block; padding: 12px 24px; background-color: #e65100; color: #fff; text-decoration: none; border-radius: 4px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin:0;">Reminder: Approval Pending</h2>
        </div>
        <div class="body">
            <p>Hello {{ $paymentRequest->currentApproval()?->approver?->name }},</p>

            <p>This is a reminder that the following payment request is still awaiting your approval.</p>

            <div class="pending-days">
                <strong>Pending since:</strong> {{ $paymentRequest->sent_at->format('d M Y, h:i A') }}
                ({{ $paymentRequest->sent_at->diffForHumans() }})
            </div>

            <div class="detail-row">
                <span class="label">Reference:</span> {{ $paymentRequest->reference_no }}
            </div>
            <div class="detail-row">
                <span class="label">Total Amount:</span>
                <span class="amount">{{ number_format($paymentRequest->total_amount, 2) }}</span>
            </div>
            <div class="detail-row">
                <span class="label">Submitted by:</span> {{ $paymentRequest->creator?->name }}
            </div>
            <div class="detail-row">
                <span class="label">Your Stage:</span> {{ $paymentRequest->currentApproval()?->label }}
            </div>

            <p>Please review and take action on this payment request through the PAF system.</p>

            <a href="{{ route('payment-request.public', ['id' => $paymentRequest->id, 'token' => $paymentRequest->currentApproval()?->view_token]) }}" class="btn">Review & Approve</a>
        </div>
        <div class="footer">
            This is an automated reminder from the Invoice Payment Approval Platform.
        </div>
    </div>
</body>
</html>
