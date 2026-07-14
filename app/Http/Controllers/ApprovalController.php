<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\ApprovalService;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function __construct(private ApprovalService $approvals)
    {
    }

    /** Queue of requests waiting on the current user's approval level. */
    public function pending(Request $request)
    {
        $user = $request->user();

        $query = Invoice::query()
            ->where('status', Invoice::STATUS_PENDING)
            ->with(['submitter:id,name,department', 'approvals.approver:id,name']);

        if (! $user->isAdmin()) {
            abort_unless($user->isApprover(), 403, 'Only approvers have an approval queue.');

            // Requests whose CURRENT stage is assigned to me, plus legacy unassigned stages at my level.
            $query->where(function ($outer) use ($user) {
                $outer->whereHas('approvals', function ($a) use ($user) {
                    $a->whereColumn('level', 'invoices.current_level')->where('approver_id', $user->id);
                })->orWhere(function ($fallback) use ($user) {
                    $fallback->where('current_level', $user->approval_level)
                        ->whereHas('approvals', function ($a) {
                            $a->whereColumn('level', 'invoices.current_level')->whereNull('approver_id');
                        });
                });
            });
        }

        return $query->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('submitted_at')
            ->paginate((int) $request->input('per_page', 15));
    }

    public function approve(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['comments' => ['nullable', 'string', 'max:2000']]);

        $invoice = $this->approvals->approve($invoice, $request->user(), $data['comments'] ?? null);

        return response()->json($invoice->load('approvals.approver:id,name'));
    }

    public function reject(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['comments' => ['required', 'string', 'max:2000']]);

        $invoice = $this->approvals->reject($invoice, $request->user(), $data['comments']);

        return response()->json($invoice->load('approvals.approver:id,name'));
    }
}
