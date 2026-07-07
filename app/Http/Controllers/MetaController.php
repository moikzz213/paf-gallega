<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\User;

class MetaController extends Controller
{
    public function index()
    {
        return response()->json([
            'categories' => config('paf.categories'),
            'departments' => config('paf.departments'),
            'currencies' => config('paf.currencies'),
            'payment_methods' => config('paf.payment_methods'),
            'priorities' => config('paf.priorities'),
            'statuses' => Invoice::STATUSES,
            'roles' => User::ROLES,
            'approval_levels' => ApprovalLevel::orderBy('level')->get(),
            'upload' => [
                'max_documents' => config('paf.max_documents'),
                'max_document_kb' => config('paf.max_document_kb'),
                'mimes' => config('paf.document_mimes'),
            ],
        ]);
    }
}
