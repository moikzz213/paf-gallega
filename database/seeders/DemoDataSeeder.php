<?php

namespace Database\Seeders;

use App\Models\ApprovalLevel;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
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

    public function run(): void
    {
        if (Invoice::count() > 0) {
            return; // don't duplicate demo data on reseed
        }

        $requesters = User::whereIn('role', [User::ROLE_REQUESTER])->get();
        $approvers = User::where('role', User::ROLE_APPROVER)->get()->keyBy('approval_level');
        $finance = User::where('role', User::ROLE_FINANCE)->first();

        $plan = [
            [Invoice::STATUS_DRAFT, 5],
            [Invoice::STATUS_PENDING, 8],
            [Invoice::STATUS_APPROVED, 6],
            [Invoice::STATUS_SCHEDULED, 4],
            [Invoice::STATUS_PAID, 28],
            [Invoice::STATUS_REJECTED, 5],
            [Invoice::STATUS_CANCELLED, 3],
        ];

        foreach ($plan as [$status, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $this->makeInvoice($status, $requesters->random(), $approvers, $finance);
            }
        }
    }

    private function makeInvoice(string $status, User $requester, $approvers, ?User $finance): void
    {
        $created = Carbon::now()->subDays(rand(0, 170))->setTime(rand(8, 17), rand(0, 59));
        $isPaidish = in_array($status, [Invoice::STATUS_PAID, Invoice::STATUS_SCHEDULED], true);
        if ($isPaidish) {
            // keep paid items spread over the whole window
            $created = Carbon::now()->subDays(rand(10, 170))->setTime(rand(8, 17), rand(0, 59));
        }

        // amount profile drives how many approval levels apply
        $amount = [rand(200, 9500), rand(10500, 48000), rand(52000, 220000)][rand(0, 2)];
        $tax = round($amount * 0.05, 2);
        $total = round($amount + $tax, 2);

        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => $this->vendors[array_rand($this->vendors)],
            'vendor_email' => 'accounts@vendor.example',
            'invoice_no' => 'INV-'.rand(10000, 99999),
            'invoice_date' => $created->copy()->subDays(rand(1, 10))->toDateString(),
            'due_date' => $created->copy()->addDays(rand(15, 45))->toDateString(),
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
            'status' => Invoice::STATUS_DRAFT,
            'submitted_by' => $requester->id,
            'created_at' => $created,
            'updated_at' => $created,
        ]);

        $this->audit($invoice, $requester, 'created', "Request {$invoice->reference_no} created for {$invoice->vendor_name} (AED {$total})", $created);

        if ($status === Invoice::STATUS_DRAFT) {
            return;
        }

        // ---- submit ----
        $submittedAt = $created->copy()->addHours(rand(1, 24));
        $levels = ApprovalLevel::requiredFor((float) $total);

        foreach ($levels as $level) {
            InvoiceApproval::create([
                'invoice_id' => $invoice->id,
                'level' => $level->level,
                'level_name' => $level->name,
                'status' => InvoiceApproval::STATUS_PENDING,
                'created_at' => $submittedAt,
                'updated_at' => $submittedAt,
            ]);
        }

        $invoice->update([
            'status' => Invoice::STATUS_PENDING,
            'current_level' => $levels->first()->level,
            'submitted_at' => $submittedAt,
        ]);
        $this->audit($invoice, $requester, 'submitted', "Request {$invoice->reference_no} submitted for approval", $submittedAt);

        if ($status === Invoice::STATUS_CANCELLED) {
            $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'current_level' => null]);
            $this->audit($invoice, $requester, 'cancelled', "Request {$invoice->reference_no} cancelled by requester", $submittedAt->copy()->addDays(1));

            return;
        }

        // ---- walk the approval chain ----
        $actedAt = $submittedAt;
        $levelList = $levels->values();
        $stopAt = null; // level index still pending (for STATUS_PENDING) or rejected

        if ($status === Invoice::STATUS_PENDING) {
            $stopAt = rand(0, $levelList->count() - 1);
        } elseif ($status === Invoice::STATUS_REJECTED) {
            $stopAt = rand(0, $levelList->count() - 1);
        }

        foreach ($levelList as $index => $level) {
            $approver = $approvers[$level->level] ?? $approvers->first();
            $actedAt = $actedAt->copy()->addHours(rand(4, 48));

            if ($status === Invoice::STATUS_PENDING && $index === $stopAt) {
                $invoice->update(['current_level' => $level->level]);

                return;
            }

            if ($status === Invoice::STATUS_REJECTED && $index === $stopAt) {
                $invoice->approvals()->where('level', $level->level)->update([
                    'status' => InvoiceApproval::STATUS_REJECTED,
                    'approver_id' => $approver->id,
                    'comments' => 'Insufficient supporting documentation.',
                    'acted_at' => $actedAt,
                ]);
                $invoice->update([
                    'status' => Invoice::STATUS_REJECTED,
                    'current_level' => null,
                    'rejected_at' => $actedAt,
                    'rejection_reason' => 'Insufficient supporting documentation.',
                ]);
                $this->audit($invoice, $approver, 'rejected', "Request {$invoice->reference_no} rejected at level {$level->level}", $actedAt);

                return;
            }

            $invoice->approvals()->where('level', $level->level)->update([
                'status' => InvoiceApproval::STATUS_APPROVED,
                'approver_id' => $approver->id,
                'comments' => 'Approved.',
                'acted_at' => $actedAt,
            ]);
            $this->audit($invoice, $approver, 'approved', "Level {$level->level} approved {$invoice->reference_no}", $actedAt);
        }

        $invoice->update([
            'status' => Invoice::STATUS_APPROVED,
            'current_level' => null,
            'approved_at' => $actedAt,
        ]);

        if ($status === Invoice::STATUS_APPROVED) {
            return;
        }

        // ---- payment processing ----
        $scheduledDate = $actedAt->copy()->addDays(rand(2, 10));
        $invoice->update(['status' => Invoice::STATUS_SCHEDULED, 'scheduled_date' => $scheduledDate->toDateString()]);
        $this->audit($invoice, $finance, 'scheduled', "Payment for {$invoice->reference_no} scheduled on {$scheduledDate->toDateString()}", $actedAt->copy()->addDays(1));

        if ($status === Invoice::STATUS_SCHEDULED) {
            return;
        }

        $paidAt = $scheduledDate->copy()->setTime(rand(9, 16), rand(0, 59));
        if ($paidAt->isFuture()) {
            $paidAt = Carbon::now()->subHours(rand(1, 48));
        }

        $invoice->update([
            'status' => Invoice::STATUS_PAID,
            'paid_at' => $paidAt,
            'payment_reference' => 'TRF-'.strtoupper(substr(md5((string) $invoice->id), 0, 8)),
            'paid_by' => $finance?->id,
        ]);
        $this->audit($invoice, $finance, 'paid', "Payment for {$invoice->reference_no} completed", $paidAt);
    }

    private function audit(Invoice $invoice, ?User $user, string $action, string $description, Carbon $at): void
    {
        AuditLog::create([
            'user_id' => $user?->id,
            'invoice_id' => $invoice->id,
            'action' => $action,
            'description' => $description,
            'ip_address' => '127.0.0.1',
            'created_at' => $at,
        ]);
    }
}
