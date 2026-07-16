<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Request {{ $paymentRequest->reference_no }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; line-height: 1.6; }
        .header { background: #1a237e; color: #fff; padding: 24px 32px; }
        .header h1 { font-size: 20px; font-weight: 600; }
        .header .ref { opacity: 0.8; font-size: 14px; margin-top: 4px; }
        .container { max-width: 800px; margin: 24px auto; padding: 0 16px; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; overflow: hidden; }
        .card-header { background: #f9f9f9; padding: 16px 20px; border-bottom: 1px solid #eee; font-weight: 600; font-size: 15px; }
        .card-body { padding: 20px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .field { margin-bottom: 12px; }
        .field .label { font-size: 12px; text-transform: uppercase; color: #888; letter-spacing: 0.5px; margin-bottom: 2px; }
        .field .value { font-size: 15px; font-weight: 500; }
        .amount { font-size: 28px; font-weight: 700; color: #1a237e; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f5f5f5; padding: 10px 16px; text-align: left; font-size: 12px; text-transform: uppercase; color: #666; letter-spacing: 0.5px; border-bottom: 2px solid #eee; }
        td { padding: 12px 16px; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        tr:last-child td { border-bottom: none; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .badge-pending { background: #fff3e0; color: #e65100; }
        .badge-approved { background: #e8f5e9; color: #2e7d32; }
        .badge-rejected { background: #fce4ec; color: #c62828; }
        .badge-in_approval { background: #e3f2fd; color: #1565c0; }
        .badge-paid { background: #e8f5e9; color: #2e7d32; }
        .chain { list-style: none; }
        .chain li { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid #f0f0f0; }
        .chain li:last-child { border-bottom: none; }
        .chain .seq { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; }
        .seq-pending { background: #e0e0e0; color: #666; }
        .seq-current { background: #1565c0; color: #fff; }
        .seq-approved { background: #2e7d32; color: #fff; }
        .seq-rejected { background: #c62828; color: #fff; }
        .chain .info { flex: 1; }
        .chain .name { font-weight: 600; font-size: 14px; }
        .chain .label { font-size: 12px; color: #888; }
        .chain .acted { font-size: 12px; color: #888; margin-top: 2px; }
        .footer { text-align: center; padding: 20px; color: #999; font-size: 12px; }
        @media (max-width: 600px) { .grid { grid-template-columns: 1fr; } }

        /* Action panel */
        .action-panel { background: #e8eaf6; border: 2px solid #1a237e; border-radius: 8px; padding: 24px; margin-bottom: 20px; text-align: center; }
        .action-panel h3 { margin-bottom: 8px; color: #1a237e; }
        .action-panel p { color: #555; margin-bottom: 20px; }
        .action-buttons { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .btn { display: inline-block; padding: 12px 32px; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; text-decoration: none; transition: opacity 0.2s; }
        .btn:hover { opacity: 0.85; }
        .btn-approve { background: #2e7d32; color: #fff; }
        .btn-reject { background: #c62828; color: #fff; }
        .btn-cancel { background: #757575; color: #fff; }

        /* Flash messages */
        .alert { padding: 14px 18px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        .alert-error { background: #fce4ec; color: #c62828; border: 1px solid #ef9a9a; }

        /* Reject modal */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal { background: #fff; border-radius: 8px; padding: 24px; width: 90%; max-width: 480px; box-shadow: 0 8px 32px rgba(0,0,0,0.2); }
        .modal h3 { margin-bottom: 12px; color: #c62828; }
        .modal textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-family: inherit; resize: vertical; min-height: 80px; }
        .modal textarea:focus { outline: none; border-color: #c62828; }
        .modal .error-text { color: #c62828; font-size: 12px; margin-top: 4px; display: none; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
        .doc-list { list-style: none; padding: 0; }
        .doc-list li { display: flex; align-items: center; gap: 8px; padding: 8px 16px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        .doc-list li:last-child { border-bottom: none; }
        .doc-list a { color: #1565c0; text-decoration: none; font-weight: 500; }
        .doc-list a:hover { text-decoration: underline; }
        .doc-list .doc-size { color: #999; font-size: 12px; margin-left: auto; }
        .doc-list .doc-icon { color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Payment Request</h1>
        <div class="ref">{{ $paymentRequest->reference_no }}</div>
    </div>

    <div class="container">
        @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
        @endif

        @if($paymentRequest->status === 'in_approval')
        @php
            $currentApproval = $paymentRequest->currentApproval();
            $currentApprover = $currentApproval?->approver;
        @endphp
        <div class="action-panel">
            @if($canAct)
            <h3>Action Required</h3>
            <p>You are viewing this as <strong>{{ $currentApprover?->name ?? 'Unknown' }}</strong>
               — Stage {{ $paymentRequest->current_stage }}: {{ $currentApproval?->label ?? '' }}</p>
            <div class="action-buttons">
                <form method="POST" action="{{ route('payment-request.public.approve', ['id' => $paymentRequest->id, 'token' => $token]) }}">
                    @csrf
                    <button type="submit" class="btn btn-approve" onclick="return confirm('Are you sure you want to approve this payment request?')">Approve</button>
                </form>
                <button type="button" class="btn btn-reject" onclick="openRejectModal()">Reject</button>
            </div>
            @else
            <h3>Awaiting Approval</h3>
            <p>This payment request is currently at <strong>Stage {{ $paymentRequest->current_stage }}: {{ $currentApproval?->label ?? '' }}</strong>
               and is awaiting action from <strong>{{ $currentApprover?->name ?? 'Unknown' }}</strong>.</p>
            <p style="margin-top:8px;color:#888;">You can view the details below but cannot take action on this link.</p>
            @endif
        </div>
        @endif

        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <div class="amount">AED {{ number_format($paymentRequest->total_amount, 2) }}</div>
                <div style="margin-top: 16px;">
                    <div class="grid">
                        <div class="field">
                            <div class="label">Status</div>
                            <div class="value">
                                <span class="badge badge-{{ $paymentRequest->status }}">{{ ucfirst(str_replace('_', ' ', $paymentRequest->status)) }}</span>
                            </div>
                        </div>
                        <div class="field">
                            <div class="label">Submitted by</div>
                            <div class="value">{{ $paymentRequest->creator?->name ?? '—' }}</div>
                        </div>
                        <div class="field">
                            <div class="label">Date Submitted</div>
                            <div class="value">{{ $paymentRequest->sent_at?->format('d M Y, h:i A') ?? '—' }}</div>
                        </div>
                        <div class="field">
                            <div class="label">Current Stage</div>
                            <div class="value">{{ $paymentRequest->current_stage ? "Stage {$paymentRequest->current_stage}" : '—' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Invoices ({{ $paymentRequest->invoices->count() }})</div>
            <div class="card-body" style="padding: 0;">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Vendor</th>
                            <th>Invoice No.</th>
                            <th style="text-align:right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($paymentRequest->invoices as $invoice)
                        <tr>
                            <td>{{ $invoice->reference_no }}</td>
                            <td>{{ $invoice->vendor_name }}</td>
                            <td>{{ $invoice->invoice_no }}</td>
                            <td style="text-align:right">{{ number_format($invoice->total_amount, 2) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" style="text-align:center;color:#999;">No invoices</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @php
            $allDocs = $paymentRequest->invoices->flatMap->documents;
        @endphp
        @if($allDocs->count())
        <div class="card">
            <div class="card-header">Attachments ({{ $allDocs->count() }})</div>
            <div class="card-body" style="padding: 0;">
                <ul class="doc-list">
                    @foreach($paymentRequest->invoices as $invoice)
                        @foreach($invoice->documents as $doc)
                        <li>
                            <span class="doc-icon">&#128196;</span>
                            <div>
                                <a href="{{ route('payment-request.public.document', ['id' => $paymentRequest->id, 'token' => $token, 'document' => $doc->id]) }}">{{ $doc->original_name }}</a>
                                <div style="font-size:11px;color:#999;">{{ $invoice->reference_no }} &middot; {{ $doc->mime_type }}</div>
                            </div>
                            <span class="doc-size">{{ round($doc->size / 1024, 1) }} KB</span>
                        </li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        </div>
        @endif

        <div class="card">
            <div class="card-header">Approval Chain</div>
            <div class="card-body">
                <ul class="chain">
                    @foreach($paymentRequest->approvals as $approval)
                    <li>
                        <div class="seq seq-{{ $approval->status }}">
                            {{ $approval->sequence }}
                        </div>
                        <div class="info">
                            <div class="name">{{ $approval->approver?->name ?? 'Unassigned' }}</div>
                            <div class="label">{{ $approval->label }}</div>
                            @if($approval->acted_at)
                            <div class="acted">{{ ucfirst($approval->status) }} {{ $approval->acted_at->format('d M Y, h:i A') }}</div>
                            @endif
                            @if($approval->comments)
                            <div class="acted" style="font-style:italic;">"{{ $approval->comments }}"</div>
                            @endif
                        </div>
                        <span class="badge badge-{{ $approval->status }}">{{ ucfirst($approval->status) }}</span>
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    <div class="footer">
        Payment Approval Flow (PAF)
    </div>

    {{-- Reject Modal --}}
    <div class="modal-overlay" id="rejectModal">
        <div class="modal">
            <h3>Reject Payment Request</h3>
            <p style="color:#555; margin-bottom:12px;">Please provide a reason for rejecting this payment request.</p>
            <form method="POST" action="{{ route('payment-request.public.reject', ['id' => $paymentRequest->id, 'token' => $token]) }}" id="rejectForm">
                @csrf
                <textarea name="comments" id="rejectComments" placeholder="Rejection reason (required)" maxlength="2000"></textarea>
                <div class="error-text" id="rejectError">Rejection reason is required.</div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-reject" onclick="return validateReject()">Reject</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openRejectModal() {
            document.getElementById('rejectModal').classList.add('active');
            document.getElementById('rejectComments').focus();
        }
        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('active');
            document.getElementById('rejectComments').value = '';
            document.getElementById('rejectError').style.display = 'none';
        }
        function validateReject() {
            var val = document.getElementById('rejectComments').value.trim();
            if (!val) {
                document.getElementById('rejectError').style.display = 'block';
                return false;
            }
            return true;
        }
    </script>
</body>
</html>
