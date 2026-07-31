<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLevel;
use App\Models\BusinessUnit;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\Vendor;

class MetaController extends Controller
{
    public function index()
    {
        return response()->json([
            'business_units' => BusinessUnit::where('is_active', true)->orderBy('name')->pluck('name'),
            'departments' => Department::where('is_active', true)->orderBy('name')->pluck('name'),
            'locations' => Location::where('is_active', true)->orderBy('name')->pluck('name'),
            'vendors' => Vendor::where('is_active', true)->orderBy('name')->get(['id', 'name', 'vendor_code', 'credit_limit', 'credit_days']),
            'customers' => Customer::where('is_active', true)->orderBy('name')->get(['id', 'name', 'customer_code', 'credit_limit', 'credit_days']),
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

    /** Active vendor names, for filter dropdowns. */
    public function vendors()
    {
        return Vendor::where('is_active', true)
            ->orderBy('name')
            ->pluck('name');
    }
}
