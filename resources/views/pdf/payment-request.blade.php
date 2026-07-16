<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #333; margin: 0; padding: 20px; }
        h1 { font-size: 18px; color: #1a237e; margin: 0 0 4px 0; }
        h2 { font-size: 14px; color: #1a237e; margin: 20px 0 8px 0; border-bottom: 2px solid #1a237e; padding-bottom: 4px; }
        h3 { font-size: 12px; color: #555; margin: 16px 0 6px 0; }
        a { color: #1565c0; text-decoration: none; }
        .header { background: #1a237e; color: #fff; padding: 16px 20px; margin: -20px -20px 20px -20px; }
        .header .ref { opacity: 0.8; font-size: 12px; margin-top: 2px; }
        .header a { color: #fff; text-decoration: underline; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .meta-table td { padding: 4px 8px; vertical-align: top; }
        .meta-table .label { font-weight: bold; color: #666; width: 140px; font-size: 10px; text-transform: uppercase; }
        .meta-table .value { font-size: 11px; }
        .amount { font-size: 22px; font-weight: bold; color: #1a237e; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.data th { background: #f0f0f0; padding: 6px 8px; text-align: left; font-size: 9px; text-transform: uppercase; color: #666; border-bottom: 2px solid #ddd; }
        table.data td { padding: 6px 8px; border-bottom: 1px solid #eee; font-size: 10px; }
        table.data tr:last-child td { border-bottom: none; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .badge-pending { background: #fff3e0; color: #e65100; }
        .badge-approved { background: #e8f5e9; color: #2e7d32; }
        .badge-rejected { background: #fce4ec; color: #c62828; }
        .badge-in_approval { background: #e3f2fd; color: #1565c0; }
        .chain-table td { vertical-align: middle; }
        .chain-num { width: 30px; text-align: center; font-weight: bold; }
        .attach-section { margin-top: 16px; page-break-inside: avoid; }
        .attach-item { border: 1px solid #ddd; border-radius: 4px; padding: 10px; margin-bottom: 10px; page-break-inside: avoid; }
        .attach-item .filename { font-weight: bold; font-size: 11px; color: #1565c0; }
        .attach-item .meta { font-size: 9px; color: #999; margin-top: 2px; }
        .attach-item img { max-width: 100%; margin-top: 8px; }
        .attach-item .pdf-frame { border: 1px solid #eee; padding: 4px; margin-top: 8px; }
        .page-break { page-break-before: always; }
        .footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 9px; color: #999; text-align: center; }
    </style>
</head>
<body>
    @php
        $appUrl = config('app.url', 'http://localhost');
    @endphp

    <div class="header" style="display:flex;align-items:center;gap:16px;">
        @php
            $logoPath = public_path('assets/images/logo.png');
            $logoExists = file_exists($logoPath);
        @endphp
        @if($logoExists)
        <img src="data:image/png;base64,{{ base64_encode(file_get_contents($logoPath)) }}" style="height:40px;">
        @endif
        <div>
            <h1>Payment Request — <a href="{{ $appUrl }}/payment-requests/{{ $paymentRequest->id }}">{{ $paymentRequest->reference_no }}</a></h1>
            <div class="ref">Generated {{ now()->format('d M Y, h:i A') }}</div>
        </div>
    </div>

    <table class="meta-table">
        <tr>
            <td class="label">Reference</td>
            <td class="value"><a href="{{ $appUrl }}/payment-requests/{{ $paymentRequest->id }}">{{ $paymentRequest->reference_no }}</a></td>
            <td class="label">Status</td>
            <td class="value"><span class="badge badge-{{ $paymentRequest->status }}">{{ ucfirst(str_replace('_', ' ', $paymentRequest->status)) }}</span></td>
        </tr>
        <tr>
            <td class="label">Total Amount</td>
            <td class="value amount">{{ $paymentRequest->total_amount ? number_format($paymentRequest->total_amount, 2) : '0.00' }}</td>
            <td class="label">Submitted by</td>
            <td class="value">{{ $paymentRequest->creator?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Date Submitted</td>
            <td class="value">{{ $paymentRequest->sent_at?->format('d M Y, h:i A') ?? '—' }}</td>
            <td class="label">Current Stage</td>
            <td class="value">{{ $paymentRequest->current_stage ? "Stage {$paymentRequest->current_stage}" : '—' }}</td>
        </tr>
        @if($paymentRequest->approved_at)
        <tr>
            <td class="label">Approved at</td>
            <td class="value">{{ $paymentRequest->approved_at->format('d M Y, h:i A') }}</td>
            <td class="label"></td>
            <td class="value"></td>
        </tr>
        @endif
        @if($paymentRequest->paid_at)
        <tr>
            <td class="label">Paid at</td>
            <td class="value">{{ $paymentRequest->paid_at->format('d M Y, h:i A') }}</td>
            <td class="label">Payment ref</td>
            <td class="value">{{ $paymentRequest->payment_reference }}</td>
        </tr>
        @endif
        @if($paymentRequest->rejection_reason)
        <tr>
            <td class="label">Rejection reason</td>
            <td class="value" colspan="3">{{ $paymentRequest->rejection_reason }}</td>
        </tr>
        @endif
    </table>

    <h2>Invoices ({{ $paymentRequest->invoices->count() }})</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Ref</th>
                <th>Vendor</th>
                <th>Invoice No</th>
                <th>Date</th>
                <th>Due</th>
                <th>Currency</th>
                <th style="text-align:right">Amount</th>
                <th style="text-align:right">Tax</th>
                <th style="text-align:right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($paymentRequest->invoices as $invoice)
            <tr>
                <td><a href="{{ $appUrl }}/invoices/{{ $invoice->id }}">{{ $invoice->reference_no }}</a></td>
                <td>{{ $invoice->vendor_name }}</td>
                <td>{{ $invoice->invoice_no }}</td>
                <td>{{ $invoice->invoice_date?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $invoice->due_date?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $invoice->currency }}</td>
                <td style="text-align:right">{{ number_format($invoice->amount, 2) }}</td>
                <td style="text-align:right">{{ number_format($invoice->tax_amount, 2) }}</td>
                <td style="text-align:right"><strong>{{ number_format($invoice->total_amount, 2) }}</strong></td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @foreach($paymentRequest->invoices as $invoice)
    @if($invoice->description || $invoice->business_unit || $invoice->department || $invoice->location)
    <h3><a href="{{ $appUrl }}/invoices/{{ $invoice->id }}">{{ $invoice->reference_no }}</a></h3>
    <table class="meta-table" style="margin-bottom:4px;">
        @if($invoice->business_unit || $invoice->department || $invoice->location)
        <tr>
            @if($invoice->business_unit)
            <td class="label">Business Unit</td>
            <td class="value">{{ $invoice->business_unit }}</td>
            @endif
            @if($invoice->department)
            <td class="label">Department</td>
            <td class="value">{{ $invoice->department }}</td>
            @endif
            @if($invoice->location)
            <td class="label">Location</td>
            <td class="value">{{ $invoice->location }}</td>
            @endif
        </tr>
        @endif
    </table>
    @if($invoice->description)
    <p style="font-size:10px;margin:0 0 8px 0;">{{ $invoice->description }}</p>
    @endif
    @endif
    @endforeach

    <h2>Approval Chain</h2>
    <table class="data chain-table">
        <thead>
            <tr>
                <th style="width:30px">#</th>
                <th>Stage</th>
                <th>Approver</th>
                <th>Status</th>
                <th>Action Date</th>
                <th>Comments</th>
            </tr>
        </thead>
        <tbody>
            @foreach($paymentRequest->approvals as $approval)
            <tr>
                <td class="chain-num">{{ $approval->sequence }}</td>
                <td>{{ $approval->label }}</td>
                <td>{{ $approval->approver?->name ?? '—' }}</td>
                <td><span class="badge badge-{{ $approval->status }}">{{ ucfirst($approval->status) }}</span></td>
                <td>{{ $approval->acted_at?->format('d/m/Y H:i') ?? '—' }}</td>
                <td>{{ $approval->comments ?: '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @php
        $allDocs = $paymentRequest->invoices->flatMap->documents;
    @endphp

    @if($allDocs->count())
    <div class="page-break"></div>
    <h2>Attachments ({{ $allDocs->count() }})</h2>

    @foreach($paymentRequest->invoices as $invoice)
        @if($invoice->documents->count())
        <h3><a href="{{ $appUrl }}/invoices/{{ $invoice->id }}">{{ $invoice->reference_no }}</a> — {{ $invoice->vendor_name }}</h3>

        @foreach($invoice->documents as $doc)
        <div class="attach-item">
            <div class="filename"><a href="{{ $appUrl }}/api/documents/{{ $doc->id }}/download">{{ $doc->original_name }}</a></div>
            <div class="meta">{{ $doc->mime_type }} &middot; {{ round($doc->size / 1024, 1) }} KB</div>

            @php
                $filePath = storage_path('app/private/' . $doc->file_path);
                $exists = file_exists($filePath);
                $isImage = in_array($doc->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
                $isPdf = $doc->mime_type === 'application/pdf';
            @endphp

            @if($exists && $isImage)
                <img src="data:{{ $doc->mime_type }};base64,{{ base64_encode(file_get_contents($filePath)) }}">
            @elseif($exists && $isPdf)
                <div class="pdf-frame">
                    @php
                        $pdfBytes = file_get_contents($filePath);
                        $base64 = base64_encode($pdfBytes);
                    @endphp
                    <iframe src="data:application/pdf;base64,{{ $base64 }}" style="width:100%;height:500px;border:none;"></iframe>
                </div>
            @elseif(!$exists)
                <div class="meta" style="color:#c62828;">File not found on disk</div>
            @else
                <div class="meta">Preview not available for this file type. File is stored on the server.</div>
            @endif
        </div>
        @endforeach
        @endif
    @endforeach
    @endif

    <div class="footer">
        Payment Approval Flow (PAF) &mdash; <a href="{{ $appUrl }}/payment-requests/{{ $paymentRequest->id }}">{{ $paymentRequest->reference_no }}</a> &mdash; Generated {{ now()->format('d M Y, h:i A') }}
    </div>
</body>
</html>
