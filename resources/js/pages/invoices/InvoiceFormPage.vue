<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import api, { errorMessage } from '../../services/api';
import { fileSize, money } from '../../utils/format';
import { useMetaStore } from '../../stores/meta';
import { useNotifyStore } from '../../stores/notify';

const props = defineProps({ id: { type: String, default: null } });

const meta = useMetaStore();
const notify = useNotifyStore();
const router = useRouter();

const isEdit = computed(() => !!props.id);
const loading = ref(false);
const saving = ref(false);
const formRef = ref(null);
const files = ref([]);
const existingDocuments = ref([]);

const form = ref({
    vendor_name: '',
    vendor_email: '',
    vendor_trn: '',
    invoice_no: '',
    invoice_date: '',
    due_date: '',
    currency: 'AED',
    amount: null,
    tax_amount: 0,
    business_unit: null,
    department: null,
    location: null,
    payment_method: 'bank_transfer',
    priority: 'normal',
    description: '',
});

const rules = {
    required: (v) => (v !== null && v !== undefined && v !== '') || 'Required',
    positive: (v) => Number(v) > 0 || 'Must be greater than zero',
    nonNegative: (v) => v === '' || v === null || Number(v) >= 0 || 'Cannot be negative',
    email: (v) => !v || /.+@.+\..+/.test(v) || 'Invalid email',
};

const totalAmount = computed(() => (Number(form.value.amount) || 0) + (Number(form.value.tax_amount) || 0));

const paymentMethodOptions = computed(() =>
    Object.entries(meta.payment_methods).map(([value, title]) => ({ value, title }))
);

onMounted(async () => {
    await meta.load();
    if (isEdit.value) {
        loading.value = true;
        try {
            const { data } = await api.get(`/invoices/${props.id}`);
            if (!['submitted', 'query_raised'].includes(data.status) || data.payment_status !== 'not_initiated') {
                notify.error('This invoice can no longer be edited.');
                router.replace(`/invoices/${props.id}`);
                return;
            }
            Object.keys(form.value).forEach((key) => {
                if (data[key] !== undefined && data[key] !== null) form.value[key] = data[key];
            });
            existingDocuments.value = data.documents ?? [];
        } finally {
            loading.value = false;
        }
    }
});

async function save() {
    const { valid } = await formRef.value.validate();
    if (!valid) {
        notify.error('Please fix the highlighted fields.');
        return;
    }

    saving.value = true;
    try {
        const payload = new FormData();
        Object.entries(form.value).forEach(([key, value]) => {
            if (value !== null && value !== '' && value !== undefined) payload.append(key, value);
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
    <div style="max-width: 980px">
        <div class="d-flex align-center mb-6">
            <v-btn icon="mdi-arrow-left" variant="text" class="mr-2" @click="router.back()" />
            <div>
                <h1 class="text-h5 font-weight-bold">{{ isEdit ? 'Edit Invoice' : 'Submit Invoice' }}</h1>
                <div class="text-body-2 text-medium-emphasis">
                    Submit a vendor invoice to Finance — it is logged date-wise for posting and payment
                </div>
            </div>
        </div>

        <div v-if="loading" class="d-flex justify-center py-16">
            <v-progress-circular indeterminate color="primary" size="48" />
        </div>

        <v-form v-else ref="formRef" autocomplete="off" @submit.prevent>
            <v-card class="mb-4">
                <v-card-title class="text-subtitle-1">Vendor & Invoice</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.vendor_name" label="Vendor name *" :rules="[rules.required]" />
                        </v-col>
                        <v-col cols="12" sm="6" md="3">
                            <v-text-field v-model="form.vendor_email" label="Vendor email" :rules="[rules.email]" />
                        </v-col>
                        <v-col cols="12" sm="6" md="3">
                            <v-text-field v-model="form.vendor_trn" label="Vendor TRN" />
                        </v-col>
                        <v-col cols="12" sm="6" md="4">
                            <v-text-field v-model="form.invoice_no" label="Vendor invoice number *" :rules="[rules.required]" />
                        </v-col>
                        <v-col cols="6" md="4">
                            <v-text-field v-model="form.invoice_date" label="Vendor invoice date *" type="date" :rules="[rules.required]" />
                        </v-col>
                        <v-col cols="6" md="4">
                            <v-text-field v-model="form.due_date" label="Due date" type="date" />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <v-card class="mb-4">
                <v-card-title class="text-subtitle-1">Amount</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="6" sm="3" md="2">
                            <v-select v-model="form.currency" :items="meta.currencies" label="Currency *" />
                        </v-col>
                        <v-col cols="6" sm="4" md="3">
                            <v-text-field v-model="form.amount" label="Amount *" type="number" min="0" step="0.01" :rules="[rules.required, rules.positive]" />
                        </v-col>
                        <v-col cols="6" sm="4" md="3">
                            <v-text-field v-model="form.tax_amount" label="Tax / VAT" type="number" min="0" step="0.01" :rules="[rules.nonNegative]" />
                        </v-col>
                        <v-col cols="6" sm="4" md="4" class="d-flex align-center">
                            <div>
                                <div class="text-caption text-medium-emphasis">Total (amount + tax)</div>
                                <div class="text-h6 font-weight-bold">{{ money(totalAmount, form.currency) }}</div>
                            </div>
                        </v-col>
                    </v-row>
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
                            <v-select v-model="form.priority" :items="meta.priorities" label="Priority *" />
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
                        />
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
