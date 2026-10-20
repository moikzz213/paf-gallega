<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.5; }
        .container { max-width: 1000px; margin: 0 auto; padding: 20px; }
        .header { background-color: #b45309; color: #fff; padding: 20px; border-radius: 4px 4px 0 0; }
        .body { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .footer { background-color: #eee; padding: 15px; border-radius: 0 0 4px 4px; font-size: 12px; color: #666; }
        .summary { background:#fffbeb; border:1px solid #fcd34d; padding:12px; border-radius:4px; margin:12px 0; }
        .label { font-weight: bold; color: #555; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 12px; }
        th { background: #f3f4f6; text-align: left; padding: 6px 8px; border: 1px solid #ddd; }
        td { padding: 6px 8px; border: 1px solid #ddd; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .old { color: #b91c1c; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin:0;">Weekly Query Raised Report &mdash; {{ $department }}</h2>
        </div>
        <div class="body">
            <p>Dear Department Head,</p>

            <p>The invoices below were submitted by your department and queried by Finance. They are
               still waiting for a correction. Until each is corrected and resubmitted it cannot be
               posted, and the vendor cannot be paid.</p>

            <div class="summary">
                <span class="label">Open queries:</span> {{ count($rows) }}
                &nbsp;&middot;&nbsp;
                <span class="label">Value:</span>
                {{ collect($totals)->map(fn ($amount, $currency) => \App\Support\Money::format($amount, $currency))->implode(', ') }}
                &nbsp;&middot;&nbsp;
                <span class="label">As of:</span> {{ $reportDate }}
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Invoice Number</th>
                        <th>PAF Number</th>
                        <th>Vendor Name</th>
                        <th class="num">Invoice Amount</th>
                        <th>Submitter Name</th>
                        <th>Query Raised</th>
                        <th class="num">Days Open</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td><a href="{{ $row['url'] }}">{{ $row['invoice_no'] ?: $row['reference_no'] }}</a></td>
                            <td>{{ $row['paf_no'] ?: '—' }}</td>
                            <td>{{ $row['vendor'] }}</td>
                            <td class="num">{{ \App\Support\Money::format($row['amount'], $row['currency']) }}</td>
                            <td>{{ $row['submitter'] ?: '—' }}</td>
                            <td>{{ $row['query'] }}@if ($row['raised_at'])<br><span style="color:#666;">Raised {{ $row['raised_at'] }}</span>@endif</td>
                            <td class="num {{ ($row['days_open'] ?? 0) >= 7 ? 'old' : '' }}">{{ $row['days_open'] ?? '—' }}</td>
                            <td>{{ $row['remarks'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <p style="margin-top:16px;">Please ask the submitters to correct and resubmit these invoices. Each
               invoice number opens the invoice in the PAF system.</p>
        </div>
        <div class="footer">
            This is an automated weekly report from the Invoice Payment Approval Platform. You receive it
            as a nominated Department Head of {{ $department }}, in weeks when the department has open queries.
        </div>
    </div>
</body>
</html>
