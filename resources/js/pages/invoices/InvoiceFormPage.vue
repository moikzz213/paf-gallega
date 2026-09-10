<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import api, { errorMessage } from '../../services/api';
import { fileSize, money } from '../../utils/format';
import { useAuthStore } from '../../stores/auth';
import { useMetaStore } from '../../stores/meta';
import { useNotifyStore } from '../../stores/notify';

const props = defineProps({ id: { type: String, default: null } });

const auth = useAuthStore();
const meta = useMetaStore();
const notify = useNotifyStore();
const router = useRouter();

const isEdit = computed(() => !!props.id);
// Set when Finance is correcting an invoice held by a payment request: currency and amounts are
// locked to what that request's approvals cover.
const correction = ref(null);
// The tax figure the invoice was loaded with. Lines created before tax became a percentage carry a
// rate recovered from their cash figure, and an arbitrary figure is not always expressible as one —
// so re-saving can move the tax by a cent or two. Surfaced rather than applied silently.
const loadedTax = ref(null);
const loading = ref(false);
const saving = ref(false);
const formRef = ref(null);
const files = ref([]);
const existingDocuments = ref([]);
// The document awaiting a remove confirmation, and the id currently being deleted.
const documentToRemove = ref(null);
const removingDocument = ref(null);
// Credit-note lines are confirmed once before saving; the flag survives the dialog so the second
// save goes straight through, and resets whenever the lines change.
const confirmingCredits = ref(false);
const creditsConfirmed = ref(false);

const form = ref({
    vendor_id: null,
    invoice_no: '',
    invoice_date: '',
    due_date: '',
    currency: 'AED',
    business_unit: null,
    department: null,
    location: null,
    payment_method: 'bank_transfer',
    priority: 'normal',
    description: '',
});

const items = ref([
    { job_no: '', customer_id: null, description: '', currency: 'AED', amount: null, tax_rate: 0 },
]);

const rules = {
    required: (v) => (v !== null && v !== undefined && v !== '') || 'Required',
    // A negative amount is a vendor credit note, netted off the invoice. Zero is neither.
    nonZero: (v) => Math.round(Number(v) * 100) !== 0 || 'Cannot be zero',
    nonNegative: (v) => v === '' || v === null || Number(v) >= 0 || 'Cannot be negative',
    percentage: (v) => v === '' || v === null || (Number(v) >= 0 && Number(v) <= 100) || 'Must be between 0 and 100',
    itemsMin: () => items.value.length > 0 || 'At least one line item is required',
};

const paymentMethodOptions = computed(() =>
    Object.entries(meta.payment_methods).map(([value, title]) => ({ value, title }))
);

const vendorItems = computed(() =>
    (meta.vendors ?? []).map((v) => ({
        title: v.name + (v.vendor_code ? ` (${v.vendor_code})` : ''),
        value: v.id,
        credit_days: v.credit_days ?? 0,
    }))
);

const customerItems = computed(() =>
    (meta.customers ?? []).map((c) => ({
        title: c.name + (c.customer_code ? ` (${c.customer_code})` : ''),
        value: c.id,
    }))
);

const priorityItems = computed(() =>
    (meta.priorities ?? []).map((p) => {
        const vendor = (meta.vendors ?? []).find((v) => v.id === form.value.vendor_id);
        if (p === 'normal' && vendor?.credit_days) {
            return { value: p, title: `Normal (${vendor.credit_days} days)` };
        }
        return { value: p, title: PRIORITY_LABELS[p] ?? p };
    })
);

const PRIORITY_LABELS = { normal: 'Normal', high: 'High (2 days)', urgent: 'Urgent (24hrs)' };

const selectedVendor = computed(() =>
    (meta.vendors ?? []).find((v) => v.id === form.value.vendor_id)
);

function calcDueDate(invoiceDate, creditDays) {
    if (!invoiceDate || !creditDays) return '';
    const d = new Date(invoiceDate);
    d.setDate(d.getDate() + creditDays);
    return d.toISOString().slice(0, 10);
}

function onVendorSelected() {
    const v = selectedVendor.value;
    if (v && form.value.invoice_date) {
        form.value.due_date = calcDueDate(form.value.invoice_date, v.credit_days);
    }
}

watch(() => form.value.invoice_date, () => {
    const v = selectedVendor.value;
    if (v) {
        form.value.due_date = calcDueDate(form.value.invoice_date, v.credit_days);
    }
});

// Tax is a percentage of the line amount. Rounded per line exactly as the server does
// (InvoiceController::validatedItems), so what the form previews is what gets saved.
function itemTax(item) {
    const amount = Number(item.amount) || 0;
    const rate = Number(item.tax_rate) || 0;
    return Math.round(amount * rate) / 100;
}

function itemTotal(item) {
    return (Number(item.amount) || 0) + itemTax(item);
}

/**
 * The invoice header totals are sums of the lines, so an invoice carries a single currency.
 * Picking one on any line sets it for the whole invoice — that is what gets saved and shown.
 */
function onItemCurrencyChange(currency) {
    if (!currency) return;
    form.value.currency = currency;
    items.value.forEach((item) => { item.currency = currency; });
}

// A negative line is a vendor credit note. Flagged as it is typed so a mistyped minus sign shows
// itself immediately, and confirmed on save (see creditsToConfirm) rather than only server-side.
function isCredit(item) {
    return Math.round((Number(item.amount) || 0) * 100) < 0;
}

const grandTotal = computed(() => items.value.reduce((s, i) => s + itemTotal(i), 0));
const creditLines = computed(() => items.value.filter(isCredit));
const creditTotal = computed(() => creditLines.value.reduce((s, i) => s + itemTotal(i), 0));
// Mirrors the server rules in InvoiceController::validatedItems. An invoice may net below zero —
// a credit-only invoice, settled by grouping it with the charge it offsets in a payment request —
// but exactly zero is neither a charge nor a credit and there is nothing to record.
const netsToZero = computed(() => Math.round(grandTotal.value * 100) === 0);
const creditOnly = computed(() => Math.round(grandTotal.value * 100) < 0);

// Editing a line after confirming means the figures the confirmation covered have moved on.
watch(items, () => { creditsConfirmed.value = false; }, { deep: true });

function confirmCredits() {
    creditsConfirmed.value = true;
    confirmingCredits.value = false;
    save();
}
// In correction mode the approvals already given cover a fixed figure, so the total may fall but
// never rise. Mirrors InvoiceController::assertInPlaceCorrection.
const correctionExceeded = computed(() => !!correction.value && grandTotal.value > Number(correction.value.total) + 0.001);
const grandAmount = computed(() => items.value.reduce((s, i) => s + (Number(i.amount) || 0), 0));
const grandTax = computed(() => items.value.reduce((s, i) => s + itemTax(i), 0));
const taxWillShift = computed(() =>
    loadedTax.value !== null && Math.abs(grandTax.value - loadedTax.value) > 0.005);

function addItem() {
    items.value.push({ job_no: '', customer_id: null, description: '', currency: form.value.currency, amount: null, tax_rate: 0 });
}

function removeItem(index) {
    if (items.value.length > 1) {
        items.value.splice(index, 1);
    }
}

onMounted(async () => {
    await meta.load();
    if (!isEdit.value) {
        // Currencies come from master data, so the 'AED' default only holds while it is on the
        // list — otherwise start on the first currency Finance has set up.
        const currencies = meta.currencies ?? [];
        if (currencies.length && !currencies.includes(form.value.currency)) {
            onItemCurrencyChange(currencies[0]);
        }
    }
    if (isEdit.value) {
        loading.value = true;
        try {
            const { data } = await api.get(`/invoices/${props.id}`);
            const editable = ['submitted', 'query_raised'].includes(data.status) && data.payment_status === 'not_initiated';
            // Finance/admin can correct an invoice a payment request is still holding, but the
            // currency and the totals are locked — those are what its approvals cover. Anything
            // else needs the invoice released first. Mirrors InvoiceController::update.
            const correctable = auth.canProcessPayments
                && ['in_approval', 'approved_for_payment'].includes(data.payment_status)
                && data.status !== 'cancelled';

            if (!editable && !correctable) {
                notify.error('This invoice can no longer be edited.');
                router.replace(`/invoices/${props.id}`);
                return;
            }
            loadedTax.value = Number(data.tax_amount ?? 0);
            if (!editable) {
                correction.value = {
                    reference_no: data.payment_request?.reference_no ?? 'its payment request',
                    currency: data.currency,
                    total: data.total_amount,
                };
            }
            Object.keys(form.value).forEach((key) => {
                if (data[key] !== undefined && data[key] !== null) form.value[key] = data[key];
            });
            if (!form.value.vendor_id) {
                const matchingVendors = (meta.vendors ?? []).filter((vendor) => vendor.name === data.vendor_name);
                if (matchingVendors.length === 1) form.value.vendor_id = matchingVendors[0].id;
            }
            if (data.items?.length) {
                items.value = data.items.map((i) => ({
                    job_no: i.job_no ?? '',
                    customer_id: i.customer_id ?? null,
                    description: i.description ?? '',
                    currency: i.currency ?? data.currency ?? 'AED',
                    amount: i.amount,
                    tax_rate: i.tax_rate ?? 0,
                }));
                // The lines hold the real currency; older invoices were saved with an AED header.
                onItemCurrencyChange(items.value[0].currency);
            }
            existingDocuments.value = data.documents ?? [];
        } finally {
            loading.value = false;
        }
    }
});

function downloadDocument(doc) {
    window.open(`/api/documents/${doc.id}/download`, '_blank');
}

// Only the uploader (or an admin) may remove a document, and only while the invoice is still
// editable — an in-place correction is held by a payment request, so its documents stay put.
function canRemoveDocument(doc) {
    return !correction.value && (auth.isAdmin || doc.uploaded_by === auth.user?.id);
}

async function removeDocument() {
    const doc = documentToRemove.value;
    if (!doc) return;

    removingDocument.value = doc.id;
    try {
        await api.delete(`/documents/${doc.id}`);
        existingDocuments.value = existingDocuments.value.filter((d) => d.id !== doc.id);
        notify.success(`${doc.original_name} removed.`);
        documentToRemove.value = null;
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        removingDocument.value = null;
    }
}

async function save() {
    if (correctionExceeded.value) {
        notify.error(`A correction cannot raise the total above ${money(correction.value.total, correction.value.currency)} — release the invoice from ${correction.value.reference_no} first.`);
        return;
    }

    if (netsToZero.value) {
        notify.error('These lines come to zero, so this invoice is neither a charge nor a credit. Enter what is owed, or enter a credit note as a negative amount.');
        return;
    }

    const { valid } = await formRef.value.validate();
    if (!valid) {
        notify.error('Please fix the highlighted fields.');
        return;
    }

    // A credit note lowers the figure the approval thresholds are measured against, so a stray
    // minus sign is worth one deliberate confirmation before it reaches Finance.
    if (creditLines.value.length && !creditsConfirmed.value) {
        confirmingCredits.value = true;
        return;
    }

    saving.value = true;
    try {
        const payload = new FormData();
        Object.entries(form.value).forEach(([key, value]) => {
            if (value !== null && value !== '' && value !== undefined) payload.append(key, value);
        });

        items.value.forEach((item, idx) => {
            payload.append(`items[${idx}][job_no]`, item.job_no || '');
            payload.append(`items[${idx}][customer_id]`, item.customer_id || '');
            payload.append(`items[${idx}][description]`, item.description || '');
            payload.append(`items[${idx}][currency]`, item.currency || form.value.currency);
            payload.append(`items[${idx}][amount]`, item.amount ?? 0);
            payload.append(`items[${idx}][tax_rate]`, item.tax_rate ?? 0);
        });

        files.value.forEach((file) => payload.append('documents[]', file));

        const url = isEdit.value ? `/invoices/${props.id}` : '/invoices';
        const { data } = await api.post(url, payload, { headers: { 'Content-Type': 'multipart/form-data' } });

        notify.success(isEdit.value ? `${data.reference_no} updated.` : `${data.reference_no} submitted to Finance.`);
        router.push(`/invoices/${data.id}`);
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div style="max-width: 1920px">
        <div class="d-flex align-center mb-6">
            <v-btn icon="mdi-arrow-left" variant="text" class="mr-2" @click="router.back()" />
            <div>
                <h1 class="text-h5 font-weight-bold">{{ correction ? 'Correct Invoice' : (isEdit ? 'Edit Invoice' : 'Submit Invoice') }}</h1>
                <div class="text-body-2 text-medium-emphasis">
                    Submit a vendor invoice to Finance — it is logged date-wise for posting and payment
                </div>
            </div>
        </div>

        <div v-if="loading" class="d-flex justify-center py-16">
            <v-progress-circular indeterminate color="primary" size="48" />
        </div>

        <v-alert v-if="correction && !loading" type="info" variant="tonal" class="mb-4" icon="mdi-lock-outline">
            This invoice is held by <strong>{{ correction.reference_no }}</strong>, whose approvals cover
            <strong>{{ money(correction.total, correction.currency) }}</strong>. You can correct its details and
            lower the total, but the <strong>currency is locked</strong> and the total cannot rise — that would
            need approvals nobody has given. To change either, release the invoice from
            {{ correction.reference_no }} and send the corrected invoice for approval again.
        </v-alert>

        <v-form v-if="!loading" ref="formRef" autocomplete="off" @submit.prevent>
            <v-card class="mb-4">
                <v-card-title class="text-subtitle-1">Vendor & Invoice</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12" md="3">
                            <v-autocomplete
                                v-model="form.vendor_id"
                                :items="vendorItems"
                                label="Vendor name *"
                                autocomplete="off"
                                :rules="[rules.required]"
                                hide-details="auto"
                                @update:model-value="onVendorSelected"
                            />
                        </v-col>
                        <v-col cols="12" sm="6" md="3">
                            <v-text-field v-model="form.invoice_no" label="Vendor invoice number *" :rules="[rules.required]" hide-details="auto"/>
                        </v-col>
                        <v-col cols="6" md="3">
                            <v-text-field v-model="form.invoice_date" label="Invoice date *" type="date" :rules="[rules.required]" hide-details="auto"/>
                        </v-col>
                        <v-col cols="6" md="3">
                            <v-text-field v-model="form.due_date" label="Due date (auto)" type="date" readonly density="comfortable" hide-details="auto"/>
                        </v-col>
                        <v-col cols="6" md="3" v-if="selectedVendor">
                            <div class="text-caption text-medium-emphasis">Credit days</div>
                            <div class="text-body-2 font-weight-medium">{{ selectedVendor.credit_days ?? 0 }} days</div>
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <v-card class="mb-4">
                <v-card-title class="text-subtitle-1 d-flex align-center">
                    Line Items
                    <v-spacer />
                    <v-btn size="small" class="pa-2" color="primary" variant="tonal" prepend-icon="mdi-plus" @click="addItem">Add More</v-btn>
                </v-card-title>
                <v-card-text>
                    <div class="text-body-2 text-medium-emphasis mb-4">
                        Enter a vendor credit note as a line with a <strong>negative amount</strong> — it is deducted
                        from the invoice, and the net figure is what goes for approval and payment. A credit note that
                        arrived on its own can be recorded as a credit-only invoice: the whole invoice comes to less
                        than zero, and it is settled by grouping it with the invoice it offsets in a payment request.
                    </div>
                    <div v-for="(item, idx) in items" :key="idx" class="mb-4 pa-4 rounded" :style="`border: 1px solid ${isCredit(item) ? 'rgba(211,47,47,0.5)' : 'rgba(0,0,0,0.12)'}`">
                        <div class="d-flex align-center mb-3">
                            <v-chip size="small" color="primary" variant="tonal" class="mr-2">Item {{ idx + 1 }}</v-chip>
                            <v-chip v-if="isCredit(item)" size="small" color="error" variant="tonal" prepend-icon="mdi-minus-circle-outline">
                                Credit note
                            </v-chip>
                            <v-spacer />
                            <v-btn v-if="items.length > 1" icon="mdi-close" size="x-small" variant="text" color="error" @click="removeItem(idx)" />
                        </div>
                        <v-row dense>
                            <v-col cols="12" sm="6" md="2">
                                <v-text-field v-model="item.job_no" label="Job No" hide-details density="compact" />
                            </v-col>
                            <v-col cols="12" sm="6" md="3">
                                <v-autocomplete
                                    v-model="item.customer_id"
                                    :items="customerItems"
                                    label="Customer"
                                    autocomplete="off"
                                    clearable
                                    hide-details
                                    density="compact"
                                />
                            </v-col> 
                            <v-col cols="6" sm="3" md="2">
                                <v-select
                                    v-model="item.currency"
                                    :items="meta.currencies"
                                    label="Currency *"
                                    :disabled="!!correction"
                                    :messages="correction ? 'Locked by the approved payment request' : undefined"
                                    hide-details="auto"
                                    density="compact"
                                    :rules="[rules.required]"
                                    @update:model-value="onItemCurrencyChange"
                                />
                            </v-col>
                            <v-col cols="6" sm="3" md="2">
                                <v-text-field
                                    v-model="item.amount"
                                    label="Amount *"
                                    type="number"
                                    step="0.01"
                                    hide-details="auto"
                                    density="compact"
                                    :rules="[rules.required, rules.nonZero]"
                                    :messages="isCredit(item) ? 'Credit note — deducted from the invoice' : undefined"
                                />
                            </v-col>
                            <v-col cols="6" sm="3" md="1">
                                <v-text-field
                                    v-model="item.tax_rate"
                                    label="Tax / VAT %"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    suffix="%"
                                    hide-details
                                    density="compact"
                                    :rules="[rules.percentage]"
                                />
                                <div class="text-caption text-medium-emphasis mt-1">
                                    {{ money(itemTax(item), item.currency) }}
                                </div>
                            </v-col>
                            <v-col cols="6" sm="3" md="2">
                                <div class="text-caption text-medium-emphasis">Total</div>
                                <div class="text-body-1 font-weight-bold" :class="isCredit(item) ? 'text-error' : ''">
                                    {{ money(itemTotal(item), item.currency) }}
                                </div>
                            </v-col>
                             <v-col cols="12" sm="6" md="12">
                                <v-text-field v-model="item.description" label="Description" hide-details density="compact" />
                            </v-col>
                        </v-row>
                    </div>
                    <v-row v-if="items.length > 1 || correction" class="mt-2">
                        <v-col cols="12">
                            <div class="d-flex justify-end align-center ga-4">
                                <template v-if="creditLines.length">
                                    <div class="text-body-2 text-medium-emphasis">
                                        Charges: <strong>{{ money(grandTotal - creditTotal, form.currency) }}</strong>
                                    </div>
                                    <div class="text-body-2 text-error">
                                        Credit notes: <strong>{{ money(creditTotal, form.currency) }}</strong>
                                    </div>
                                </template>
                                <div class="text-body-2 text-medium-emphasis">
                                    {{ creditLines.length ? 'Net payable' : 'Total' }}:
                                    <strong>{{ money(grandTotal, form.currency) }}</strong>
                                </div>
                            </div>
                        </v-col>
                    </v-row>
                    <v-alert v-if="netsToZero" type="error" variant="tonal" density="compact" class="mt-2">
                        These lines come to <strong>{{ money(0, form.currency) }}</strong>, so this invoice is neither a
                        charge nor a credit. Enter what is owed, or enter a credit note as a negative amount.
                    </v-alert>
                    <v-alert v-else-if="creditOnly" type="info" variant="tonal" density="compact" class="mt-2">
                        This invoice comes to <strong>{{ money(grandTotal, form.currency) }}</strong> — a credit-only
                        invoice, which the vendor owes back rather than one we pay. It can be recorded now and is
                        settled later by selecting it in a payment request alongside the invoice it offsets. On its own
                        it cannot be paid, because a payment request has to come to more than zero.
                    </v-alert>
                    <v-alert v-if="taxWillShift" type="info" variant="tonal" density="compact" class="mt-2">
                        This invoice was saved with {{ money(loadedTax, form.currency) }} tax. At the rates above it
                        works out to <strong>{{ money(grandTax, form.currency) }}</strong>, so saving will record that
                        instead — older lines store a rate recovered from their original tax figure, which cannot
                        always be expressed exactly as a percentage.
                    </v-alert>
                    <v-alert v-if="correctionExceeded" type="error" variant="tonal" density="compact" class="mt-2">
                        {{ money(grandTotal, form.currency) }} is above the
                        {{ money(correction.total, correction.currency) }} that {{ correction.reference_no }} was
                        approved for. Lower it, or release the invoice and send the corrected amount for approval.
                    </v-alert>
                </v-card-text>
            </v-card>

            <v-card class="mb-4">
                <v-card-title class="text-subtitle-1">Classification</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12" sm="6" md="4">
                            <v-autocomplete v-model="form.business_unit" :items="meta.business_units" label="Business Unit *" autocomplete="off" :rules="[rules.required]" />
                        </v-col>
                        <v-col cols="12" sm="6" md="4">
                            <v-select v-model="form.department" :items="meta.departments" label="Submitting department *" :rules="[rules.required]" />
                        </v-col>
                        <v-col cols="12" sm="6" md="4">
                            <v-autocomplete v-model="form.location" :items="meta.locations" label="Location *" autocomplete="off" :rules="[rules.required]" />
                        </v-col>
                        <v-col cols="12" sm="6" md="4">
                            <v-select v-model="form.payment_method" :items="paymentMethodOptions" label="Payment method *" />
                        </v-col>
                        <v-col cols="12" sm="6" md="4">
                            <v-select v-model="form.priority" :items="priorityItems" label="Priority *" />
                        </v-col>
                        <v-col cols="12">
                            <v-textarea v-model="form.description" label="Description of supply / service" rows="3" auto-grow counter="5000" />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <v-card class="mb-6">
                <v-card-title class="text-subtitle-1">Supporting Documents</v-card-title>
                <v-card-text>
                    <v-list v-if="existingDocuments.length" density="compact" class="mb-2">
                        <v-list-item
                            v-for="doc in existingDocuments"
                            :key="doc.id"
                            :title="doc.original_name"
                            :subtitle="fileSize(doc.size)"
                            prepend-icon="mdi-paperclip"
                        >
                            <template #append>
                                <v-btn
                                    icon="mdi-download"
                                    variant="text"
                                    size="small"
                                    title="Download"
                                    @click="downloadDocument(doc)"
                                />
                                <v-btn
                                    v-if="canRemoveDocument(doc)"
                                    icon="mdi-delete-outline"
                                    variant="text"
                                    size="small"
                                    color="error"
                                    title="Remove"
                                    :loading="removingDocument === doc.id"
                                    @click="documentToRemove = doc"
                                />
                            </template>
                        </v-list-item>
                    </v-list>
                    <v-file-input
                        v-model="files"
                        label="Attach invoice copy, PO, delivery note…"
                        multiple
                        chips
                        show-size
                        prepend-icon=""
                        prepend-inner-icon="mdi-paperclip"
                        :hint="`Up to ${meta.upload.max_documents} files, ${Math.round(meta.upload.max_document_kb / 1024)}MB each (${meta.upload.mimes})`"
                        persistent-hint
                    />
                </v-card-text>
            </v-card>

            <v-dialog v-model="confirmingCredits" max-width="520">
                <v-card>
                    <v-card-title class="text-subtitle-1">Confirm the credit notes</v-card-title>
                    <v-card-text class="text-body-2">
                        <p class="mb-3">
                            This invoice has
                            <strong>{{ creditLines.length }} line{{ creditLines.length === 1 ? '' : 's' }}</strong>
                            entered as a credit note, deducting
                            <strong class="text-error">{{ money(-creditTotal, form.currency) }}</strong>.
                        </p>
                        <v-table density="compact" class="mb-3">
                            <tbody>
                                <tr v-for="(item, idx) in creditLines" :key="idx">
                                    <td>{{ item.description || item.job_no || 'Credit note' }}</td>
                                    <td class="text-right text-error">{{ money(itemTotal(item), item.currency) }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                        <p v-if="creditOnly">
                            The invoice comes to <strong>{{ money(grandTotal, form.currency) }}</strong> — the whole
                            invoice is a credit, so there is nothing to pay on it. Check it before continuing: it can
                            only be settled by grouping it with the invoice it offsets in a payment request.
                        </p>
                        <p v-else>
                            The invoice comes to <strong>{{ money(grandTotal, form.currency) }}</strong>, and that is
                            the figure that goes for approval and payment. Check it before continuing — a credit
                            lowers the amount, and so lowers who has to approve it.
                        </p>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" @click="confirmingCredits = false">Go back</v-btn>
                        <v-btn color="primary" variant="flat" @click="confirmCredits">Confirm &amp; save</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <v-dialog :model-value="!!documentToRemove" max-width="440" @update:model-value="documentToRemove = null">
                <v-card>
                    <v-card-title class="text-subtitle-1">Remove attachment</v-card-title>
                    <v-card-text class="text-body-2">
                        <strong>{{ documentToRemove?.original_name }}</strong> will be deleted from this invoice.
                        This cannot be undone.
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" @click="documentToRemove = null">Cancel</v-btn>
                        <v-btn color="error" variant="flat" :loading="!!removingDocument" @click="removeDocument">Remove</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <div class="d-flex ga-3">
                <v-btn color="primary" size="large" :loading="saving" prepend-icon="mdi-send" @click="save">
                    {{ isEdit ? 'Save Changes' : 'Submit to Finance' }}
                </v-btn>
                <v-spacer />
                <v-btn variant="text" size="large" @click="router.back()">Cancel</v-btn>
            </div>
        </v-form>
    </div>
</template>
