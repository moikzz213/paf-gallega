<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::query()
            ->with(['user:id,name,role', 'invoice:id,reference_no', 'paymentRequest:id,reference_no']);

        if ($action = $request->input('action')) {
            $query->where('action', $action);
        }

        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($q = trim((string) $request->input('q'))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('description', 'like', "%{$q}%")
                    ->orWhereHas('invoice', fn ($i) => $i->where('reference_no', 'like', "%{$q}%"))
                    ->orWhereHas('paymentRequest', fn ($p) => $p->where('reference_no', 'like', "%{$q}%"));
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        return $query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 25));
    }

    public function actions()
    {
        return response()->json(
            AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action')
        );
    }
}
