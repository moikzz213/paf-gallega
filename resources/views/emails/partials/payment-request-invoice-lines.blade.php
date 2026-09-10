@if($paymentRequest->invoices->count())
    <h3>Invoices Included</h3>

    @foreach($paymentRequest->invoices as $invoice)
        <div style="margin: 0 0 16px; border: 1px solid #ddd; background: #fff;">
            <div style="padding: 10px 12px; background: #f0f0f0; font-size: 13px;">
                <strong>{{ $invoice->reference_no }}</strong> &mdash;
                {{ $invoice->vendor_name }} / {{ $invoice->invoice_no }}<br>
                <strong>Invoice submitted by:</strong> {{ $invoice->submitter?->name ?? '—' }}
            </div>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; margin: 0;">
                    <thead>
                        <tr>
                            <th style="padding: 7px 8px; text-align: left; border-bottom: 1px solid #ddd; font-size: 11px;">Job No.</th>
                            <th style="padding: 7px 8px; text-align: left; border-bottom: 1px solid #ddd; font-size: 11px;">Customer</th>
                            <th style="padding: 7px 8px; text-align: left; border-bottom: 1px solid #ddd; font-size: 11px;">Description</th>
                            <th style="padding: 7px 8px; text-align: right; border-bottom: 1px solid #ddd; font-size: 11px;">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($invoice->items as $item)
                            <tr>
                                <td style="padding: 7px 8px; border-bottom: 1px solid #eee; font-size: 12px;">{{ $item->job_no ?: '—' }}</td>
                                <td style="padding: 7px 8px; border-bottom: 1px solid #eee; font-size: 12px;">{{ $item->customer?->name ?? '—' }}</td>
                                <td style="padding: 7px 8px; border-bottom: 1px solid #eee; font-size: 12px;">{{ $item->description ?: ($invoice->description ?: '—') }}</td>
                                <td style="padding: 7px 8px; border-bottom: 1px solid #eee; font-size: 12px; text-align: right;">{{ \App\Support\Money::format($item->total_amount, $item->currency ?: $invoice->currency) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td style="padding: 7px 8px; font-size: 12px;">—</td>
                                <td style="padding: 7px 8px; font-size: 12px;">—</td>
                                <td style="padding: 7px 8px; font-size: 12px;">{{ $invoice->description ?: '—' }}</td>
                                <td style="padding: 7px 8px; font-size: 12px; text-align: right;">{{ \App\Support\Money::format($invoice->total_amount, $invoice->currency) }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
@endif
