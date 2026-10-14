<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BusinessUnit;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Models\Location;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Freight Forwarding files its supporting documents against a checklist, six items of which must
 * hold a file before an invoice can be submitted. Other departments are untouched, and invoices
 * submitted before the checklist are exempt from its required items.
 *
 * CR: ai/change-requests/add-freight-forwarding-document-checklist-to-invoice-submission.md
 */
class FreightForwardingDocumentChecklistTest extends TestCase
{
    use RefreshDatabase;

    private const FF = 'Freight Forwarding';

    private const REQUIRED = ['vendor_invoice', 'job_sheet', 'job_order', 'buy_rate', 'sell_rate', 'finance_approval'];

    private Vendor $vendor;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        BusinessUnit::create(['name' => 'GGH', 'is_active' => true]);
        Department::create(['name' => self::FF, 'is_active' => true]);
        Department::create(['name' => 'Yard', 'is_active' => true]);
        Location::create(['name' => 'Dubai', 'is_active' => true]);
        Currency::firstOrCreate(['name' => 'AED']);
        $this->vendor = Vendor::create(['name' => 'Ocean Lines', 'vendor_code' => 'OCL-1', 'is_active' => true]);
        $this->requester = $this->user(User::ROLE_REQUESTER);
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role).' '.uniqid(), 'email' => $role.uniqid().'@t.local',
            'password' => 'password', 'role' => $role, 'is_active' => true,
        ]);
    }

    private function file(string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
    }

    /**
     * @param  array<int, array{0: string|null, 1?: string}>  $docs  [type, label?] per file
     * @return array<string, mixed>
     */
    private function payload(string $department, array $docs = [], array $overrides = []): array
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'business_unit' => 'GGH',
            'department' => $department,
            'location' => 'Dubai',
            'payment_method' => 'bank_transfer',
            'priority' => 'normal',
            'items' => [['currency' => 'AED', 'description' => 'Ocean freight', 'amount' => 1000, 'tax_rate' => 0]],
            ...$overrides,
        ];

        foreach (array_values($docs) as $i => $doc) {
            $payload['documents'][$i] = $this->file(($doc[0] ?? 'untyped')."-{$i}.pdf");
            $payload['document_types'][$i] = $doc[0];
            $payload['document_labels'][$i] = $doc[1] ?? '';
        }

        return $payload;
    }

    /** @return array<int, array{0: string}> */
    private function requiredSet(): array
    {
        return array_map(fn ($type) => [$type], self::REQUIRED);
    }

    private function submit(array $payload, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->requester)->post('/api/invoices', $payload, ['Accept' => 'application/json']);
    }

    public function test_a_freight_forwarding_invoice_with_every_required_item_is_submitted_and_filed(): void
    {
        $this->submit($this->payload(self::FF, $this->requiredSet()))->assertCreated();

        $invoice = Invoice::sole();
        $this->assertTrue($invoice->subject_to_document_checklist);
        $this->assertEqualsCanonicalizing(self::REQUIRED, $invoice->documents()->pluck('document_type')->all());
        $this->assertSame('Job Sheet', $invoice->documents()->where('document_type', 'job_sheet')->first()->type_label);
    }

    public function test_a_freight_forwarding_invoice_without_documents_is_refused_naming_every_required_item(): void
    {
        $this->submit($this->payload(self::FF))
            ->assertStatus(422)
            ->assertJsonValidationErrors('documents')
            ->assertJsonFragment(['documents' => ['Attach the required Freight Forwarding documents: Vendor Invoice, Job Sheet, Job Order / Checklist, Buy Rate, Sell Rate, Finance Approval.']]);

        $this->assertSame(0, Invoice::count());
    }

    public function test_each_required_item_is_enforced_but_the_margin_email_and_other_are_optional(): void
    {
        foreach (self::REQUIRED as $missing) {
            $docs = array_values(array_filter($this->requiredSet(), fn ($d) => $d[0] !== $missing));

            $this->submit($this->payload(self::FF, $docs))->assertStatus(422)->assertJsonValidationErrors('documents');
        }

        $this->assertSame(0, Invoice::count());

        // Neither the margin approval email nor "Other" is needed — and both are accepted.
        $this->submit($this->payload(self::FF, [...$this->requiredSet(), ['margin_approval'], ['other', 'Port receipt']]))
            ->assertCreated();
    }

    public function test_every_file_needs_an_item_and_an_other_file_needs_a_name(): void
    {
        $this->submit($this->payload(self::FF, [...$this->requiredSet(), [null]]))
            ->assertStatus(422)->assertJsonValidationErrors('document_types.6');

        $this->submit($this->payload(self::FF, [...$this->requiredSet(), ['other', '  ']]))
            ->assertStatus(422)->assertJsonValidationErrors('document_labels.6');

        $this->submit($this->payload(self::FF, [...$this->requiredSet(), ['not_a_type']]))
            ->assertStatus(422)->assertJsonValidationErrors('document_types.6');

        $this->submit($this->payload(self::FF, [...$this->requiredSet(), ['other', 'Port receipt']]))->assertCreated();

        $other = InvoiceDocument::where('document_type', 'other')->sole();
        $this->assertSame('Port receipt', $other->document_label);
        $this->assertSame('Other — Port receipt', $other->type_label);
    }

    public function test_an_item_takes_several_files_and_the_limit_is_thirty(): void
    {
        $docs = [...$this->requiredSet(), ['vendor_invoice'], ['vendor_invoice'], ['buy_rate'], ['sell_rate'], ['job_sheet']];

        $this->submit($this->payload(self::FF, $docs))->assertCreated();
        $this->assertSame(3, InvoiceDocument::where('document_type', 'vendor_invoice')->count());
        $this->assertSame(11, InvoiceDocument::count(), 'above the ordinary limit of 10');

        $tooMany = [...$this->requiredSet(), ...array_fill(0, 25, ['vendor_invoice'])];
        $this->submit($this->payload(self::FF, $tooMany))->assertStatus(422)->assertJsonValidationErrors('documents');
    }

    public function test_other_departments_are_unchanged(): void
    {
        // No checklist, no required items, any types sent are ignored, and the limit stays 10.
        $this->submit($this->payload('Yard'))->assertCreated();
        $this->submit($this->payload('Yard', [['job_sheet'], [null]]))->assertCreated();
        $this->assertSame(0, InvoiceDocument::whereNotNull('document_type')->count());

        $this->submit($this->payload('Yard', array_fill(0, 11, [null])))->assertStatus(422)->assertJsonValidationErrors('documents');
    }

    public function test_an_invoice_submitted_before_the_checklist_is_exempt_from_its_required_items(): void
    {
        $this->submit($this->payload('Yard'))->assertCreated();
        $legacy = Invoice::sole();
        $legacy->forceFill(['subject_to_document_checklist' => false, 'department' => self::FF])->save();

        // Saved with no checklist documents at all: allowed, because it predates the rule…
        $this->actingAs($this->requester)
            ->post("/api/invoices/{$legacy->id}", $this->payload(self::FF, [], ['invoice_no' => $legacy->invoice_no]), ['Accept' => 'application/json'])
            ->assertOk();

        // …but a new file on it is still filed under an item.
        $this->actingAs($this->requester)
            ->post("/api/invoices/{$legacy->id}", $this->payload(self::FF, [[null]], ['invoice_no' => $legacy->invoice_no]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('document_types.0');
    }

    public function test_removing_a_required_document_blocks_the_next_save_until_it_is_replaced(): void
    {
        $this->submit($this->payload(self::FF, $this->requiredSet()))->assertCreated();
        $invoice = Invoice::sole();
        $sheet = $invoice->documents()->where('document_type', 'job_sheet')->sole();

        $this->actingAs($this->requester)->deleteJson("/api/documents/{$sheet->id}")->assertOk();

        $edit = fn (array $docs) => $this->actingAs($this->requester)->post(
            "/api/invoices/{$invoice->id}",
            $this->payload(self::FF, $docs, ['invoice_no' => $invoice->invoice_no]),
            ['Accept' => 'application/json'],
        );

        $edit([])->assertStatus(422)->assertJsonFragment(['documents' => ['Attach the required Freight Forwarding documents: Job Sheet.']]);
        $edit([['job_sheet']])->assertOk();
    }

    public function test_a_late_upload_on_a_freight_forwarding_advance_is_filed_under_an_item(): void
    {
        $this->submit($this->payload(self::FF, $this->requiredSet(), ['is_advance_payment' => '1']))->assertCreated();
        $invoice = Invoice::sole();
        $pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(), 'created_by' => $this->user(User::ROLE_FINANCE)->id,
            'status' => PaymentRequest::STATUS_PAID, 'total_amount' => 1000, 'sent_at' => now(), 'paid_at' => now(),
        ]);
        $invoice->update(['status' => Invoice::STATUS_POSTED, 'payment_request_id' => $pr->id, 'payment_status' => Invoice::PAY_PAID]);

        $upload = fn (array $extra) => $this->actingAs($this->requester)->post("/api/invoices/{$invoice->id}/documents", [
            'documents' => [$this->file('final-tax-invoice.pdf')], ...$extra,
        ], ['Accept' => 'application/json']);

        $upload([])->assertStatus(422)->assertJsonValidationErrors('document_types.0');
        $upload(['document_types' => ['vendor_invoice']])->assertOk();

        $late = $invoice->documents()->where('original_name', 'final-tax-invoice.pdf')->sole();
        $this->assertSame('vendor_invoice', $late->document_type);
        $this->assertTrue($late->uploaded_after_approval);
    }

    public function test_the_type_is_audited_shown_and_the_document_can_be_viewed_inline(): void
    {
        $this->submit($this->payload(self::FF, $this->requiredSet()))->assertCreated();
        $invoice = Invoice::sole();
        $doc = $invoice->documents()->where('document_type', 'buy_rate')->sole();

        $this->assertTrue(AuditLog::where('action', 'document_uploaded')->where('description', 'like', '%(Buy Rate)%')->exists());

        $this->actingAs($this->requester)->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()->assertJsonFragment(['type_label' => 'Buy Rate']);

        $view = $this->actingAs($this->requester)->get("/api/documents/{$doc->id}/download?inline=1")->assertOk();
        $this->assertStringStartsWith('inline', $view->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $view->headers->get('X-Content-Type-Options'));

        $download = $this->actingAs($this->requester)->get("/api/documents/{$doc->id}/download")->assertOk();
        $this->assertStringStartsWith('attachment', $download->headers->get('Content-Disposition'));

        $this->actingAs($this->requester)->getJson('/api/meta')->assertOk()
            ->assertJsonPath('document_checklist.department', self::FF)
            ->assertJsonPath('document_checklist.max_documents', 30)
            ->assertJsonCount(8, 'document_checklist.types');
    }
}
