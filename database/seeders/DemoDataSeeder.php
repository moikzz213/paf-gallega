<?php

namespace Database\Seeders;

use App\Models\ApprovalLevel;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoDataSeeder extends Seeder
{
    private array $vendors = [
        'Gulf Fresh Trading LLC', 'Al Noor Logistics', 'Emirates Office Supplies',
        'TechBridge Solutions FZE', 'City Power & Water', 'Horizon Marketing Group',
        'Falcon Facility Services', 'Delta Freight Co', 'Prime Legal Consultants',
        'Oasis IT Distribution', 'Star Maintenance Works', 'Metro Print House',
    ];

    private $requesters;

    private $approvers;

    private ?User $finance = null;

    public function run(): void
    {
        if (Invoice::count() > 0) {
            return; // don't duplicate demo data on reseed
        }

        $this->requesters = User::where('role', User::ROLE_REQUESTER)->get();
        $this->approvers = User::where('role', User::ROLE_APPROVER)->get()->keyBy('approval_level');
        $this->finance = User::where('role', User::ROLE_FINANCE)->first();

        // Standalone invoices in the log (not yet in a payment cycle)
        for ($i = 0; $i < 6; $i++) {
            $this->makeInvoice(Invoice::STATUS_SUBMITTED);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->makeInvoice(Invoice::STATUS_QUERY);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->makeInvoice(Invoice::STATUS_CANCELLED);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->makeInvoice(Invoice::STATUS_POSTED); // eligible pool
        }

        // Payment requests in each state
        for ($i = 0; $i < 3; $i++) {
            $this->makePaymentRequest(PaymentRequest::STATUS_PAID);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->makePaymentRequest(PaymentRequest::STATUS_APPROVED);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->makePaymentRequest(PaymentRequest::STATUS_IN_APPROVAL);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->makePaymentRequest(PaymentRequest::STATUS_REJECTED);
        }
    }

    private function makeInvoice(string $status, ?Carbon $when = null): Invoice
    {
        $submitted = $when ?? Carbon::now()->subDays(rand(2, 160))->setTime(rand(8, 17), rand(0, 59));
        $amount = [rand(2000, 9500), rand(10500, 48000), rand(52000, 220000)][rand(0, 2)];
        $tax = round($amount * 0.05, 2);
        $total = round($amount + $tax, 2);
        $requester = $this->requesters->random();

        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => $this->vendors[array_rand($this->vendors)],
            'vendor_email' => 'accounts@vendor.example',
            'invoice_no' => 'INV-'.rand(10000, 99999),
            'invoice_date' => $submitted->copy()->subDays(rand(1, 10))->toDateString(),
            'due_date' => $submitted->copy()->addDays(rand(15, 45))->toDateString(),
            'currency' => 'AED',
            'amount' => $amount,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'category' => config('paf.categories')[array_rand(config('paf.categories'))],
            'department' => config('paf.departments')[array_rand(config('paf.departments'))],
            'cost_center' => 'CC-'.rand(100, 999),
            'payment_method' => array_rand(config('paf.payment_methods')),
            'priority' => config('paf.priorities')[array_rand(config('paf.priorities'))],
            'description' => 'Payment for goods/services as per attached invoice.',
            'status' => Invoice::STATUS_SUBMITTED,
            'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $requester->id,
            'submitted_at' => $submitted,
            'created_at' => $submitted,
            'updated_at' => $submitted,
        ]);

        $this->audit($invoice, $requester, 'submitted', "Invoice {$invoice->reference_no} submitted to Finance", $submitted);

        if ($status === Invoice::STATUS_QUERY) {
            $invoice->update(['status' => Invoice::STATUS_QUERY, 'finance_remarks' => 'PO number missing on invoice. Please resubmit with PO reference.']);
            $this->audit($invoice, $this->finance, 'query_raised', "Query raised on {$invoice->reference_no}", $submitted->copy()->addDays(1));
        } elseif ($status === Invoice::STATUS_CANCELLED) {
            $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
            $this->audit($invoice, $requester, 'cancelled', "Invoice {$invoice->reference_no} cancelled", $submitted->copy()->addDays(1));
        } elseif ($status === Invoice::STATUS_POSTED) {
            $postedAt = $submitted->copy()->addDays(rand(1, 6));
            $invoice->update([
                'status' => Invoice::STATUS_POSTED,
                'erp_doc_no' => '51'.rand(10000000, 99999999),
                'posting_date' => $postedAt->toDateString(),
                'posted_by' => $this->finance?->id,
                'posted_at' => $postedAt,
            ]);
            $this->audit($invoice, $this->finance, 'posted', "Invoice {$invoice->reference_no} posted in ERP", $postedAt);
        }

        return $invoice->refresh();
    }

    private function makePaymentRequest(string $targetStatus): void
    {
        $count = rand(1, 3);
        $invoices = collect();
        $created = Carbon::now()->subDays(rand(5, 120))->setTime(rand(9, 16), rand(0, 59));
        for ($i = 0; $i < $count; $i++) {
            $invoices->push($this->makeInvoice(Invoice::STATUS_POSTED, $created->copy()->subDays(rand(3, 20))));
        }
        $total = $invoices->sum('total_amount');

        $pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $this->finance?->id,
            'status' => PaymentRequest::STATUS_IN_APPROVAL,
            'current_stage' => 1,
            'total_amount' => $total,
            'sent_at' => $created,
            'created_at' => $created,
            'updated_at' => $created,
        ]);

        Invoice::whereIn('id', $invoices->pluck('id'))->update([
            'payment_request_id' => $pr->id,
            'payment_status' => Invoice::PAY_IN_APPROVAL,
        ]);

        $levels = ApprovalLevel::requiredFor((float) $total)->values();
        $stages = [];
        foreach ($levels as $idx => $level) {
            $stages[] = PaymentRequestApproval::create([
                'payment_request_id' => $pr->id,
                'sequence' => $idx + 1,
                'level' => $level->level,
                'label' => $level->name,
                'is_adhoc' => false,
                'approver_id' => $this->approvers[$level->level]->id ?? $this->approvers->first()->id,
                'status' => PaymentRequestApproval::STATUS_PENDING,
                'created_at' => $created,
                'updated_at' => $created,
            ]);
        }

        $this->audit($invoices->first(), $this->finance, 'payment_initiated', "Payment request {$pr->reference_no} created for {$count} invoice(s)", $created, $pr);

        $actedAt = $created->copy();
        $stopAt = match ($targetStatus) {
            PaymentRequest::STATUS_IN_APPROVAL => rand(0, count($stages) - 1),
            PaymentRequest::STATUS_REJECTED => rand(0, count($stages) - 1),
            default => count($stages), // approve all
        };

        foreach ($stages as $idx => $stage) {
            $actedAt = $actedAt->copy()->addHours(rand(4, 40));

            if ($targetStatus === PaymentRequest::STATUS_IN_APPROVAL && $idx === $stopAt) {
                $pr->update(['current_stage' => $stage->sequence]);

                return;
            }

            if ($targetStatus === PaymentRequest::STATUS_REJECTED && $idx === $stopAt) {
                $stage->update(['status' => PaymentRequestApproval::STATUS_REJECTED, 'comments' => 'Insufficient supporting documentation.', 'acted_at' => $actedAt]);
                $pr->update(['status' => PaymentRequest::STATUS_REJECTED, 'current_stage' => null, 'rejected_at' => $actedAt, 'rejection_reason' => 'Insufficient supporting documentation.']);
                Invoice::whereIn('id', $invoices->pluck('id'))->update(['payment_status' => Invoice::PAY_NOT_INITIATED, 'payment_request_id' => null]);
                $this->audit($invoices->first(), $this->approvers[$stage->level] ?? $this->finance, 'rejected', "Payment request {$pr->reference_no} rejected", $actedAt, $pr);

                return;
            }

            $stage->update(['status' => PaymentRequestApproval::STATUS_APPROVED, 'comments' => 'Approved.', 'acted_at' => $actedAt]);
            $this->audit($invoices->first(), $this->approvers[$stage->level] ?? $this->finance, 'approved', "Stage {$stage->sequence} approved on {$pr->reference_no}", $actedAt, $pr);
        }

        // fully approved
        $pr->update(['status' => PaymentRequest::STATUS_APPROVED, 'current_stage' => null, 'approved_at' => $actedAt]);
        Invoice::whereIn('id', $invoices->pluck('id'))->update(['payment_status' => Invoice::PAY_APPROVED]);

        if ($targetStatus === PaymentRequest::STATUS_PAID) {
            $paidAt = $actedAt->copy()->addDays(rand(1, 6));
            if ($paidAt->isFuture()) {
                $paidAt = Carbon::now()->subHours(rand(1, 48));
            }
            $pr->update([
                'status' => PaymentRequest::STATUS_PAID,
                'paid_at' => $paidAt,
                'payment_reference' => 'TRF-'.strtoupper(substr(md5((string) $pr->id), 0, 8)),
                'paid_by' => $this->finance?->id,
            ]);
            Invoice::whereIn('id', $invoices->pluck('id'))->update(['payment_status' => Invoice::PAY_PAID]);
            $this->audit($invoices->first(), $this->finance, 'paid', "Payment request {$pr->reference_no} marked paid", $paidAt, $pr);
        }
    }

    private function audit(?Invoice $invoice, ?User $user, string $action, string $description, Carbon $at, ?PaymentRequest $pr = null): void
    {
        AuditLog::create([
            'user_id' => $user?->id,
            'invoice_id' => $invoice?->id,
            'payment_request_id' => $pr?->id,
            'action' => $action,
            'description' => $description,
            'ip_address' => '127.0.0.1',
            'created_at' => $at,
        ]);
    }
}
