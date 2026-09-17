<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #b45309; color: #fff; padding: 20px; border-radius: 4px 4px 0 0; }
        .body { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .footer { background-color: #eee; padding: 15px; border-radius: 0 0 4px 4px; font-size: 12px; color: #666; }
        .detail-row { margin: 8px 0; }
        .label { font-weight: bold; color: #555; }
        .query { background:#fffbeb; border:1px solid #fcd34d; padding:12px; border-radius:4px; margin:12px 0; }
        .btn { display: inline-block; padding: 12px 24px; background-color: #b45309; color: #fff; text-decoration: none; border-radius: 4px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin:0;">Query Raised on Your Invoice</h2>
        </div>
        <div class="body">
            <p>Hello {{ $invoice->submitter?->name }},</p>

            <p>Finance has raised a query on an invoice you submitted. Please review the note below,
               correct the invoice and resubmit it.</p>

            <div class="detail-row"><span class="label">Reference:</span> {{ $invoice->reference_no }}</div>
            <div class="detail-row"><span class="label">Vendor:</span> {{ $invoice->vendor_name }}</div>
            <div class="detail-row"><span class="label">Invoice No:</span> {{ $invoice->invoice_no }}</div>
            <div class="detail-row"><span class="label">Department:</span> {{ $invoice->department }}</div>

            <div class="query">
                <span class="label">Query / Remarks:</span><br>
                {{ $invoice->finance_remarks }}
            </div>

            <a href="{{ url('/invoices/'.$invoice->id) }}" class="btn">Open Invoice</a>
        </div>
        <div class="footer">
            This is an automated notification from the Invoice Payment Approval Platform.
        </div>
    </div>
</body>
</html>
