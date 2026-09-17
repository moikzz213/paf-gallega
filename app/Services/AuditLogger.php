<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PaymentRequest;

class AuditLogger
{
    public static function log(
        string $action,
        string $description,
        ?Invoice $invoice = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?PaymentRequest $paymentRequest = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => auth()->id(),
            'invoice_id' => $invoice?->id,
            'payment_request_id' => $paymentRequest?->id,
            'action' => $action,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }
}
