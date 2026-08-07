<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use App\Services\AuditLogger;
use App\Services\PaymentRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PublicPaymentRequestController extends Controller
{
    public function __construct(private PaymentRequestService $service) {}

    public function show(string $id, string $token)
    {
        $pr = $this->resolvePr($id, $token);
        $canAct = $this->canAct($pr, $token);

        return view('public.payment-request', [
            'paymentRequest' => $pr,
            'canAct' => $canAct,
            'token' => $token,
        ]);
    }

    public function approve(Request $request, string $id, string $token)
    {
        $pr = $this->resolvePr($id, $token);

        if (! $this->canAct($pr, $token)) {
            return redirect()->route('payment-request.public', ['id' => $pr->id, 'token' => $token])
                ->with('error', 'This link is no longer valid for approval. Please check your email for the updated link.');
        }

        $approval = $this->getActionableApproval($pr, $token);
        $approver = $approval?->approver;

        if (! $approver) {
            return redirect()->route('payment-request.public', ['id' => $pr->id, 'token' => $token])
                ->with('error', 'No approver is assigned for the current stage.');
        }

        $finalized = false;
        $next = null;

        DB::transaction(function () use ($pr, $approval, $approver, &$finalized, &$next) {
            $approval->update([
                'status' => PaymentRequestApproval::STATUS_APPROVED,
                'acted_at' => now(),
            ]);

            $next = $pr->approvals()
                ->where('status', PaymentRequestApproval::STATUS_PENDING)
                ->orderBy('sequence')
                ->first();

            if ($next) {
                $pr->update(['current_stage' => $next->sequence]);
                AuditLogger::log('approved', "Stage {$approval->sequence} approved on {$pr->reference_no} via public link by {$approver->name}; moved to {$next->label}", null, null, null, $pr);
            } else {
                $pr->update([
                    'status' => PaymentRequest::STATUS_APPROVED,
                    'current_stage' => null,
                    'approved_at' => now(),
                ]);
                $pr->invoices()->update(['payment_status' => Invoice::PAY_APPROVED]);
                AuditLogger::log('approved', "Payment request {$pr->reference_no} fully approved via public link by {$approver->name} — released for payment", null, null, null, $pr);
                $finalized = true;
            }
        });

        $pr->refresh();

        // Both notifications go through PaymentRequestService so this path and the in-app one
        // cannot drift again, and both send after the commit rather than inside it.
        if ($finalized) {
            $this->service->notifyApproved($pr);
        } else {
            $this->service->notifyNextApprover($pr, $next);
        }

        return redirect()->route('payment-request.public', ['id' => $pr->id, 'token' => $token])
            ->with('success', 'Payment request has been approved successfully.');
    }

    public function reject(Request $request, string $id, string $token)
    {
        $pr = $this->resolvePr($id, $token);

        if (! $this->canAct($pr, $token)) {
            return redirect()->route('payment-request.public', ['id' => $pr->id, 'token' => $token])
                ->with('error', 'This link is no longer valid for rejection. Please check your email for the updated link.');
        }

        $approval = $this->getActionableApproval($pr, $token);
        $approver = $approval?->approver;

        if (! $approver) {
            return redirect()->route('payment-request.public', ['id' => $pr->id, 'token' => $token])
                ->with('error', 'No approver is assigned for the current stage.');
        }

        $request->validate([
            'comments' => 'required|string|max:2000',
        ]);

        DB::transaction(function () use ($pr, $approval, $approver, $request) {
            $approval->update([
                'status' => PaymentRequestApproval::STATUS_REJECTED,
                'comments' => $request->input('comments'),
                'acted_at' => now(),
            ]);

            $stage = $approval->sequence;

            $pr->update([
                'status' => PaymentRequest::STATUS_REJECTED,
                'current_stage' => null,
                'rejected_at' => now(),
                'rejection_reason' => $request->input('comments'),
            ]);

            $pr->invoices()->update([
                'payment_status' => Invoice::PAY_NOT_INITIATED,
                'payment_request_id' => null,
            ]);

            AuditLogger::log('rejected', "Payment request {$pr->reference_no} rejected at stage {$stage} via public link by {$approver->name}. Invoices returned to Finance.", null, null, null, $pr);
        });

        $pr->refresh();

        return redirect()->route('payment-request.public', ['id' => $pr->id, 'token' => $token])
            ->with('success', 'Payment request has been rejected.');
    }

    public function downloadDocument(string $prfId, string $token, InvoiceDocument $document)
    {
        $pr = $this->resolvePr($prfId, $token);

        $belongs = $pr->invoices->contains($document->invoice_id);
        if (! $belongs) {
            abort(404);
        }

        abort_unless(Storage::disk('local')->exists($document->file_path), 404, 'File not found on disk.');

        return Storage::disk('local')->download($document->file_path, $document->original_name);
    }

    /**
     * Check if the token grants approval action.
     * Returns true only if the token matches a pending approval stage that is the current stage.
     */
    private function canAct(PaymentRequest $pr, string $token): bool
    {
        if ($pr->status !== PaymentRequest::STATUS_IN_APPROVAL) {
            return false;
        }

        $approval = $this->getActionableApproval($pr, $token);

        return $approval !== null;
    }

    /**
     * Find the approval stage that matches the token AND is the current actionable stage.
     */
    private function getActionableApproval(PaymentRequest $pr, string $token): ?PaymentRequestApproval
    {
        return $pr->approvals()
            ->where('view_token', $token)
            ->where('sequence', $pr->current_stage)
            ->where('status', PaymentRequestApproval::STATUS_PENDING)
            ->first();
    }

    private function resolvePr(string $id, string $token): PaymentRequest
    {
        $pr = PaymentRequest::with([
            'creator',
            'invoices.submitter:id,name',
            'invoices.items.customer:id,name',
            'invoices.documents',
            'approvals.approver',
        ])->find($id);

        if (! $pr) {
            abort(404);
        }

        // Allow access if token matches the PRF view_token OR any approval stage's view_token
        $matchesPrf = $pr->view_token === $token;
        $matchesApproval = $pr->approvals->contains('view_token', $token);

        if (! $matchesPrf && ! $matchesApproval) {
            abort(404);
        }

        return $pr;
    }
}
