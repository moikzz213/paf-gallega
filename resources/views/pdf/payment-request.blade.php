<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 7mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111; font-family: DejaVu Sans, sans-serif; font-size: 6.5px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: middle; }
        .sheet { width: 100%; }
        .top-line { border-top: 1.5px solid #222; }
        .heading td { height: 36px; border-bottom: 1px solid #555; }
        .logo-cell { width: 48px; padding: 2px 3px; text-align: left; }
        .company-logo { display: block; width: 32px; height: 32px; }
        .company { padding-left: 0; font-size: 9px; font-weight: normal; }
        .form-name { padding-right: 3px; font-size: 9px; font-weight: bold; text-align: right; }
        .voucher td { height: 17px; }
        .voucher-spacer { width: 63%; }
        .voucher-label { width: 13%; font-size: 7px; font-weight: bold; text-align: center; }
        .voucher-value { width: 24%; border-bottom: 1px solid #555; font-size: 7px; font-weight: bold; text-align: center; }
        .details { margin-top: 2px; table-layout: fixed; }
        .details td { height: 17px; border: 1px solid #666; }
        .details .label { width: 17%; background: #d9d9d9; padding: 2px 4px; font-weight: bold; text-align: center; }
        .details .value { width: 33%; padding: 2px 5px; font-weight: bold; text-align: center; }
        .lines { margin-top: 4px; table-layout: fixed; }
        .lines th { height: 29px; padding: 2px; border: 1px solid #666; background: #d9d9d9; font-size: 6px; text-align: center; }
        .lines td { height: 18px; padding: 2px 3px; border: 1px solid #777; font-weight: bold; overflow-wrap: break-word; }
        .center { text-align: center; }
        .number { text-align: right; white-space: nowrap; }
        .total-row td { height: 19px; font-weight: bold; }
        .amount-words-label { background: #d9d9d9; text-align: left; }
        .amount-words { font-size: 6px; text-transform: uppercase; }
        .form-row td { height: 14px; border: 1px solid #666; }
        .form-row .label { width: 17%; padding: 1px 4px; background: #d9d9d9; font-weight: bold; }
        .form-row .value { padding: 1px 5px; font-style: italic; }
        .comments { height: 14px; padding: 1px 5px !important; font-style: normal !important; }
        .workflow { margin-top: 4px; page-break-inside: avoid; }
        .workflow-head th { height: 18px; border: 1px solid #555; font-size: 7px; font-style: italic; }
        .workflow-body { table-layout: fixed; }
        .workflow-zone { padding: 0; vertical-align: top; border-right: 1.5px solid #333; }
        .workflow-zone:last-child { border-right: 0; }
        .option-row { height: 23px; padding: 3px 4px; border-bottom: 1px solid #777; font-size: 6px; font-style: italic; }
        .option-box { display: inline-block; width: 24px; height: 14px; margin: 0 3px 0 8px; border: 1px solid #555; vertical-align: middle; }
        .flow { width: 100%; table-layout: auto; }
        .flow td { padding: 0; text-align: center; vertical-align: top; }
        .card-cell { padding: 5px 0 0 !important; }
        .arrow-cell { width: 15px; padding: 0 !important; vertical-align: top !important; }
        .arrow-link { height: 1px; margin-top: 24px; border-top: 1.5px solid #315d93; line-height: 0; }
        .arrow-link span { float: right; width: 0; height: 0; margin-top: -4px; margin-right: -1px; border-top: 4px solid transparent; border-bottom: 4px solid transparent; border-left: 7px solid #315d93; font-size: 0; line-height: 0; }
        .signature-box { height: 38px; padding: 3px 2px; border: 1px solid #555; font-size: 6px; font-weight: bold; text-align: center; }
        .signature-box.not-required { border-style: dashed; border-color: #aaa; background: #f4f4f4; color: #999; font-weight: normal; }
        .signature-date { margin-top: 2px; font-size: 5px; font-weight: normal; }
        .signature-action { min-height: 25px; padding: 5px 1px 1px; font-size: 6px; font-weight: bold; }
        .signature-role { min-height: 35px; padding: 5px 1px 2px; font-size: 5.5px; font-weight: bold; }
        .zone-bottom { border-bottom: 1.5px solid #333; }
        .footer-space { height: 7px; }
        .page-break { page-break-before: always; }
        .attachment-title { margin: 0 0 8px; padding-bottom: 4px; border-bottom: 2px solid #444; font-size: 13px; }
        .attachment-invoice { margin: 12px 0 5px; font-size: 9px; }
        .attach-item { margin-bottom: 10px; padding: 8px; border: 1px solid #aaa; page-break-inside: avoid; }
        .filename { font-size: 8px; font-weight: bold; }
        .attachment-meta { margin-top: 2px; color: #666; font-size: 6px; }
        .attach-item img { display: block; max-width: 100%; max-height: 165mm; margin: 7px auto 0; }
        .embedded-file-note { margin-top: 7px; padding: 12px; border: 1px solid #bbb; background: #f2f2f2; text-align: center; }
        a { color: inherit; text-decoration: none; }
    </style>
</head>
<body>
    @php
        $companyLogo = base64_encode(file_get_contents(public_path('images/gallega-global-logistics-logo.png')));
        $invoices = $paymentRequest->invoices;
        $approvals = $paymentRequest->approvals;
        $currencies = $invoices->pluck('currency')->filter()->unique()->values();
        $displayCurrency = $currencies->count() === 1 ? $currencies->first() : 'MULTI-CURRENCY';
        $departments = $invoices->pluck('department')->filter()->unique()->implode(', ');
        $requesters = $invoices->pluck('submitter.name')->filter()->unique()->implode(', ');
        $paymentMethods = $invoices->pluck('payment_method')->filter()->unique()->map(
            fn ($method) => config("paf.payment_methods.{$method}", ucfirst(str_replace('_', ' ', $method)))
        )->implode(', ');
        $erpDocuments = $invoices->pluck('erp_doc_no')->filter()->unique()->implode(', ');
        $postedBy = $invoices->pluck('poster.name')->filter()->unique()->implode(', ');
        $comments = $paymentRequest->rejection_reason
            ?: $approvals->pluck('comments')->filter()->implode(' | ');
        $amountWords = 'Amount shown numerically';
        if ($currencies->count() === 1 && class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter('en', NumberFormatter::SPELLOUT);
            $whole = (int) floor((float) $paymentRequest->total_amount);
            $fraction = (int) round((((float) $paymentRequest->total_amount) - $whole) * 100);
            $amountWords = trim($formatter->format($whole).' '.$displayCurrency.($fraction ? " and {$fraction}/100" : '').' only');
        }
        $companyName = 'GALLEGA GLOBAL LOGISTICS SINGLE OWNER L.L.C (DUBAI BRANCH)';
        $approvalLimit = 'Up to AED 50,000 by Finance Manager. All above AED 50,000 by Gallega CEO or SVP - Group Finance';
        $requisitionApprovals = $approvals->filter(
            fn ($approval) => $approval->approvalLevel
                && (float) $approval->approvalLevel->min_amount === 0.0
        )->values();
        $remainingApprovals = $approvals->reject(
            fn ($approval) => $requisitionApprovals->contains('id', $approval->id)
        )->values();
        $requisitionCards = collect([
            ['name' => $requesters ?: ($paymentRequest->creator?->name ?? '-'), 'date' => ($paymentRequest->sent_at ?? $paymentRequest->created_at)?->format('d/m/Y'), 'action' => 'Prepared By / Requested By', 'role' => $departments ?: 'Executive / Officer'],
        ])->concat($requisitionApprovals->map(fn ($approval) => [
            'name' => $approval->approver?->name ?? '',
            'date' => $approval->acted_at?->format('d/m/Y') ?? '',
            'action' => 'Approved By',
            'role' => $approval->label,
        ]));

        // For Approval column: show EVERY configured approval level above the requisition
        // threshold. Levels the amount reached are the required (in-chain) approvers; levels
        // not reached are still displayed (as their default approver) but marked Not Required.
        $reachedByLevel = $approvals->whereNotNull('level')->keyBy('level');
        $forApprovalCards = collect();
        foreach ($approvalLevels->filter(fn ($lvl) => (float) $lvl->min_amount > 0.0) as $lvl) {
            if ($reachedByLevel->has($lvl->level)) {
                $ap = $reachedByLevel->get($lvl->level);
                $forApprovalCards->push([
                    'name' => $ap->approver?->name ?? '',
                    'date' => $ap->acted_at?->format('d/m/Y'),
                    'action' => $ap->status === 'approved' ? 'Approved By' : ($ap->status === 'rejected' ? 'Rejected By' : 'Pending Approval'),
                    'role' => $lvl->name,
                    'required' => true,
                ]);
            } else {
                $forApprovalCards->push([
                    'name' => $lvl->defaultApprover?->name ?? '',
                    'date' => null,
                    'action' => 'Approved By',
                    'role' => $lvl->name,
                    'required' => false,
                ]);
            }
        }
        // Ad-hoc stages (no approval level) are always required — append them.
        foreach ($approvals->whereNull('level') as $ap) {
            $forApprovalCards->push([
                'name' => $ap->approver?->name ?? '',
                'date' => $ap->acted_at?->format('d/m/Y'),
                'action' => $ap->status === 'approved' ? 'Approved By' : ($ap->status === 'rejected' ? 'Rejected By' : 'Pending Approval'),
                'role' => $ap->label,
                'required' => true,
            ]);
        }
        $accountsCards = [
            ['name' => $paymentRequest->payer?->name ?? '', 'date' => $paymentRequest->paid_at?->format('d/m/Y'), 'action' => 'Acknowledged / Paid By', 'role' => 'Finance Officer'],
            ['name' => $postedBy, 'date' => '', 'action' => 'Verified / Posted By', 'role' => 'Finance Officer'],
            ['name' => '', 'date' => '', 'action' => 'Audited By', 'role' => 'Finance Officer'],
        ];
    @endphp

    <div class="sheet">
        <table class="heading top-line">
            <tr>
                <td class="logo-cell"><img class="company-logo" src="data:image/png;base64,{{ $companyLogo }}" alt="Gallega Global Logistics"></td>
                <td class="company">{{ $companyName }}</td>
                <td class="form-name">Invoice Payment Approval Platform</td>
            </tr>
        </table>

        <table class="voucher">
            <tr>
                <td class="voucher-spacer"></td>
                <td class="voucher-label">VOUCHER #</td>
                <td class="voucher-value">{{ $paymentRequest->reference_no }}</td>
            </tr>
            <tr>
                <td class="voucher-spacer"></td>
                <td class="voucher-label">VOUCHER DATE</td>
                <td class="voucher-value">{{ ($paymentRequest->sent_at ?? $paymentRequest->created_at)?->format('d/m/Y') }}</td>
            </tr>
        </table>

        <table class="details">
            <tr>
                <td class="label">REQUESTED BY (NAME)</td>
                <td class="value">{{ $requesters ?: ($paymentRequest->creator?->name ?? '-') }}</td>
                <td class="label">DEPARTMENT HEAD NAME</td>
                <td class="value">{{ $requisitionApprovals->first()?->approver?->name ?? $approvals->first()?->approver?->name ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">DEPARTMENT NAME</td>
                <td class="value">{{ $departments ?: '-' }}</td>
                <td class="label">MODE OF PAYMENT</td>
                <td class="value">{{ $paymentMethods ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">PURCHASE LIMIT</td>
                <td class="value">&nbsp;</td>
                <td class="label">CASH LIMIT</td>
                <td class="value">&nbsp;</td>
            </tr>
            <tr>
                <td class="label">DOCUMENT NO. (FOR ACCOUNTS)</td>
                <td class="value">{{ $erpDocuments ?: '-' }}</td>
                <td class="label">DOCUMENT NO. (FOR ACCOUNTS)</td>
                <td class="value">{{ $paymentRequest->payment_reference ?: '-' }}</td>
            </tr>
        </table>

        <table class="lines">
            <colgroup>
                <col style="width:3%"><col style="width:14%"><col style="width:8%"><col style="width:10%"><col style="width:20%">
                <col style="width:15%"><col style="width:8%"><col style="width:9%"><col style="width:6%"><col style="width:7%">
            </colgroup>
            <thead>
                <tr>
                    <th>SR #</th>
                    <th>SUPPLIER NAME</th>
                    <th>SUPPLIER CODE</th>
                    <th>SUPPLIER INVOICE #</th>
                    <th>DESCRIPTION</th>
                    <th>JOB #</th>
                    <th>DATE OF EXPENSE</th>
                    <th>AMOUNT</th>
                    <th>VAT</th>
                    <th>AMOUNT IN {{ $displayCurrency }}</th>
                </tr>
            </thead>
            <tbody>
                @php $sr = 0; @endphp
                @foreach($invoices as $invoice)
                    @forelse($invoice->items as $item)
                        @php $sr++; $cur = $item->currency ?: $invoice->currency; @endphp
                        <tr>
                            <td class="center">{{ $sr }}</td>
                            <td>{{ $invoice->vendor_name }}</td>
                            <td class="center">{{ ($supplierCodes[$invoice->vendor_name] ?? null) ?: '-' }}</td>
                            <td class="center">{{ $invoice->invoice_no }}</td>
                            <td>{{ $item->description ?: ($invoice->description ?: '-') }}</td>
                            <td class="center">{{ $item->job_no ?: '-' }}</td>
                            <td class="center">{{ $invoice->invoice_date?->format('d/m/Y') ?? '-' }}</td>
                            <td class="number">{{ $cur }} {{ number_format((float) $item->amount, 2) }}</td>
                            <td class="number">{{ $cur }} {{ number_format((float) $item->tax_amount, 2) }}</td>
                            <td class="number">{{ $cur }} {{ number_format((float) $item->total_amount, 2) }}</td>
                        </tr>
                    @empty
                        @php $sr++; @endphp
                        <tr>
                            <td class="center">{{ $sr }}</td>
                            <td>{{ $invoice->vendor_name }}</td>
                            <td class="center">{{ ($supplierCodes[$invoice->vendor_name] ?? null) ?: '-' }}</td>
                            <td class="center">{{ $invoice->invoice_no }}</td>
                            <td>{{ $invoice->description ?: '-' }}</td>
                            <td class="center">-</td>
                            <td class="center">{{ $invoice->invoice_date?->format('d/m/Y') ?? '-' }}</td>
                            <td class="number">{{ $invoice->currency }} {{ number_format((float) $invoice->amount, 2) }}</td>
                            <td class="number">{{ $invoice->currency }} {{ number_format((float) $invoice->tax_amount, 2) }}</td>
                            <td class="number">{{ $invoice->currency }} {{ number_format((float) $invoice->total_amount, 2) }}</td>
                        </tr>
                    @endforelse
                @endforeach
                @for($row = $sr; $row < 6; $row++)
                    <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                @endfor
                <tr class="total-row">
                    <td class="amount-words-label" colspan="2">AMOUNT IN WORDS</td>
                    <td class="amount-words" colspan="5">{{ $amountWords }}</td>
                    <td class="center">TOTAL</td>
                    <td colspan="2" class="number">{{ $displayCurrency }} {{ number_format((float) $paymentRequest->total_amount, 2) }}</td>
                </tr>
            </tbody>
        </table>

        <table class="form-row">
            <tr>
                <td class="label">APPROVALS LIMIT FOR PAYMENT</td>
                <td class="value">{{ $approvalLimit }}</td>
            </tr>
            <tr>
                <td class="label">COMMENTS / OBSERVATION</td>
                <td class="value comments">{{ $comments }}</td>
            </tr>
        </table>

        <div class="workflow">
            <table class="workflow-head">
                <tr>
                    <th style="width:29%">For Requisition Dept. Use</th>
                    <th style="width:46%">For Approval</th>
                    <th style="width:25%">For Accounts Dept. Use</th>
                </tr>
            </table>
            <table class="workflow-body">
                <tr>
                    <td class="workflow-zone zone-bottom" style="width:29%">
                        <div class="option-row">&nbsp;</div>
                        <table class="flow">
                            <tr>
                                @foreach($requisitionCards as $card)
                                    <td class="card-cell">
                                        <div class="signature-box">{{ $card['name'] }}@if($card['date'])<div class="signature-date">{{ $card['date'] }}</div>@endif</div>
                                        <div class="signature-action">{{ $card['action'] }}</div>
                                        <div class="signature-role">{{ $card['role'] }}</div>
                                    </td>
                                    @if(! $loop->last)<td class="arrow-cell"><div class="arrow-link"><span>&#9654;</span></div></td>@endif
                                @endforeach
                            </tr>
                        </table>
                    </td>
                    <td class="workflow-zone zone-bottom" style="width:46%">
                        <div class="option-row">
                            <span class="option-box"></span> Budgeted according to policy
                            <span class="option-box"></span> Not Budgeted
                        </div>
                        @forelse($forApprovalCards->chunk(5) as $cardRow)
                            <table class="flow">
                                <tr>
                                    @foreach($cardRow as $card)
                                        <td class="card-cell">
                                            <div class="signature-box{{ $card['required'] ? '' : ' not-required' }}">
                                                {{ $card['name'] }}
                                                @if($card['date'])<div class="signature-date">{{ $card['date'] }}</div>@endif
                                            </div>
                                            <div class="signature-action">{{ $card['action'] }}</div>
                                            <div class="signature-role">{{ $card['role'] }}</div>
                                        </td>
                                        @if(! $loop->last)<td class="arrow-cell"><div class="arrow-link"><span>&#9654;</span></div></td>@endif
                                    @endforeach
                                </tr>
                            </table>
                        @empty
                            <div class="option-row">&nbsp;</div>
                        @endforelse
                    </td>
                    <td class="workflow-zone zone-bottom" style="width:25%">
                        <div class="option-row">&nbsp;</div>
                        <table class="flow">
                            <tr>
                                @foreach($accountsCards as $card)
                                    <td class="card-cell">
                                        <div class="signature-box">{{ $card['name'] }}@if($card['date'])<div class="signature-date">{{ $card['date'] }}</div>@endif</div>
                                        <div class="signature-action">{{ $card['action'] }}</div>
                                        <div class="signature-role">{{ $card['role'] }}</div>
                                    </td>
                                    @if(! $loop->last)<td class="arrow-cell"><div class="arrow-link"><span>&#9654;</span></div></td>@endif
                                @endforeach
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>
        <div class="footer-space"></div>
    </div>
    {{-- Attachments are appended by PdfMergeService: PDFs/images are merged inline, and
         everything else is listed as clickable download links on a trailing page. That list
         cannot live here — FPDI's merge step discards link annotations produced by dompdf. --}}
</body>
</html>
