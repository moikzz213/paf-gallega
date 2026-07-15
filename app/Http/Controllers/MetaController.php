<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;

class MetaController extends Controller
{
    public function index()
    {
        return response()->json([
            'business_units' => config('paf.business_units'),
            'departments' => config('paf.departments'),
            'locations' => config('paf.locations'),
            'currencies' => config('paf.currencies'),
            'payment_methods' => config('paf.payment_methods'),
            'priorities' => config('paf.priorities'),
            'statuses' => Invoice::STATUSES,
            'payment_statuses' => Invoice::PAYMENT_STATUSES,
            'pr_statuses' => PaymentRequest::STATUSES,
            'roles' => User::ROLES,
            'approval_levels' => ApprovalLevel::with('defaultApprover:id,name')->orderBy('level')->get(),
            'upload' => [
                'max_documents' => config('paf.max_documents'),
                'max_document_kb' => config('paf.max_document_kb'),
                'mimes' => config('paf.document_mimes'),
            ],
        ]);
    }

    /** Active users who can be assigned as approvers on a request (for the chain builder). */
    public function approvers()
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', [User::ROLE_APPROVER, User::ROLE_ADMIN])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'approval_level', 'department', 'job_title']);
    }
}
