<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import api, { errorMessage } from '../../services/api';
import { dateTime, fileSize, money, shortDate, PRIORITY_META } from '../../utils/format';
import { useAuthStore } from '../../stores/auth';
import { useNotifyStore } from '../../stores/notify';
import StatusChip from '../../components/StatusChip.vue';

const props = defineProps({ id: { type: String, required: true } });

const auth = useAuthStore();
const notify = useNotifyStore();
const router = useRouter();

const invoice = ref(null);
const loading = ref(true);
const acting = ref(false);

const dialog = ref({ show: false, kind: null, erp_doc_no: '', posting_date: '', finance_remarks: '' });

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get(`/invoices/${props.id}`);
        invoice.value = data;
    } catch (e) {
        notify.error(errorMessage(e));
        router.replace('/invoices');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

const isOwner = computed(() => invoice.value?.submitted_by === auth.user?.id);
const notInitiated = computed(() => invoice.value?.payment_status === 'not_initiated');

const canEdit = computed(() => (isOwner.value || auth.isAdmin) && ['submitted', 'query_raised'].includes(invoice.value?.status) && notInitiated.value);
const canCancel = computed(() => (isOwner.value || auth.isAdmin) && notInitiated.value && invoice.value?.status !== 'cancelled');
const canDelete = computed(() => (isOwner.value || auth.isAdmin) && notInitiated.value);
const canPost = computed(() => auth.canProcessPayments && ['submitted', 'query_raised'].includes(invoice.value?.status));
const canQuery = computed(() => auth.canProcessPayments && ['submitted', 'posted'].includes(invoice.value?.status));

const pr = computed(() => invoice.value?.payment_request);

function openDialog(kind) {
    dialog.value = { show: true, kind, erp_doc_no: '', posting_date: '', finance_remarks: '' };
}

const dialogTitle = computed(() => ({ post: 'Post to ERP', query: 'Raise Query' }[dialog.value.kind]));

async function runAction(kind, payload = {}) {
    acting.value = true;
    try {
        const urls = {
            post: `/invoices/${props.id}/post`,
            query: `/invoices/${props.id}/query`,
            cancel: `/invoices/${props.id}/cancel`,
        };
        await api.post(urls[kind], payload);
        notify.success({ post: 'Invoice posted in ERP.', query: 'Query raised.', cancel: 'Invoice cancelled.' }[kind]);
        dialog.value.show = false;
        await load();
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        acting.value = false;
    }
}

function confirmDialog() {
    const { kind, erp_doc_no, posting_date, finance_remarks } = dialog.value;
    if (kind === 'post') {
        if (!erp_doc_no.trim()) return notify.error('ERP document number is required.');
        return runAction('post', { erp_doc_no, posting_date: posting_date || undefined });
    }
    if (kind === 'query') {
        if (!finance_remarks.trim()) return notify.error('A query note is required.');
        return runAction('query', { finance_remarks });
    }
}

async function deleteInvoice() {
    if (!confirm('Delete this invoice permanently?')) return;
    try {
        await api.delete(`/invoices/${props.id}`);
        notify.success('Invoice deleted.');
        router.replace('/invoices');
    } catch (e) {
        notify.error(errorMessage(e));
    }
}

function download(doc) {
    window.open(`/api/documents/${doc.id}/download`, '_blank');
}

function approvalIcon(status) {
    return { approved: 'mdi-check-circle', rejected: 'mdi-close-circle', pending: 'mdi-circle-outline' }[status];
}
function approvalColor(status) {
    return { approved: '#1baf7a', rejected: '#e34948', pending: '#898781' }[status];
}

const auditIcons = {
    submitted: 'mdi-send',
    updated: 'mdi-pencil-outline',
    posted: 'mdi-checkbox-marked-circle-outline',
    query_raised: 'mdi-help-circle-outline',
    payment_initiated: 'mdi-bank-transfer',
    approved: 'mdi-thumb-up-outline',
    rejected: 'mdi-thumb-down-outline',
    paid: 'mdi-cash-check',
    cancelled: 'mdi-cancel',
    document_uploaded: 'mdi-paperclip',
    document_deleted: 'mdi-paperclip-off',
};
</script>

<template>
    <div v-if="loading" class="d-flex justify-center py-16">
        <v-progress-circular indeterminate color="primary" size="48" />
    </div>

    <div v-else-if="invoice">
        <div class="d-flex align-center flex-wrap mb-6 ga-2">
            <v-btn icon="mdi-arrow-left" variant="text" @click="router.back()" />
            <div class="mr-2">
                <h1 class="text-h5 font-weight-bold d-flex align-center ga-3">
                    {{ invoice.reference_no }}
                    <StatusChip :status="invoice.status" size="default" />
                    <StatusChip v-if="invoice.payment_status !== 'not_initiated'" :status="invoice.payment_status" size="small" />
                </h1>
                <div class="text-body-2 text-medium-emphasis">
                    {{ invoice.vendor_name }} · Invoice {{ invoice.invoice_no }} · Submitted by {{ invoice.submitter?.name }}
                </div>
            </div>
            <v-spacer />

            <v-btn v-if="canPost" color="success" prepend-icon="mdi-checkbox-marked-circle-outline" @click="openDialog('post')">Post to ERP</v-btn>
            <v-btn v-if="canQuery" color="warning" variant="tonal" prepend-icon="mdi-help-circle-outline" @click="openDialog('query')">Raise Query</v-btn>
            <v-btn v-if="canEdit" variant="tonal" prepend-icon="mdi-pencil" :to="`/invoices/${invoice.id}/edit`">Edit</v-btn>
            <v-btn v-if="canCancel" variant="text" color="error" prepend-icon="mdi-cancel" :loading="acting" @click="runAction('cancel')">Cancel</v-btn>
            <v-btn v-if="canDelete" variant="text" color="error" icon="mdi-delete-outline" @click="deleteInvoice" />
        </div>

        <v-alert v-if="invoice.status === 'query_raised'" type="error" variant="tonal" class="mb-4" icon="mdi-help-circle-outline">
            <strong>Query:</strong> {{ invoice.finance_remarks }}
        </v-alert>

        <v-row>
            <v-col cols="12" md="8">
                <v-card class="mb-4">
                    <v-card-title class="text-subtitle-1">Invoice Details</v-card-title>
                    <v-card-text>
                        <v-row dense>
                            <v-col v-for="field in [
                                ['Vendor', invoice.vendor_name],
                                ['Vendor email', invoice.vendor_email || '—'],
                                ['Vendor TRN', invoice.vendor_trn || '—'],
                                ['Invoice #', invoice.invoice_no],
                                ['Invoice date', shortDate(invoice.invoice_date)],
                                ['Due date', shortDate(invoice.due_date)],
                                ['Category', invoice.category],
                                ['Department', invoice.department],
                                ['Cost center', invoice.cost_center || '—'],
                            ]" :key="field[0]" cols="6" md="4">
                                <div class="text-caption text-medium-emphasis">{{ field[0] }}</div>
                                <div class="text-body-2 font-weight-medium">{{ field[1] }}</div>
                            </v-col>
                        </v-row>
                        <v-divider class="my-4" />
                        <v-row dense>
                            <v-col cols="6" md="3">
                                <div class="text-caption text-medium-emphasis">Amount</div>
                                <div class="text-body-1">{{ money(invoice.amount, invoice.currency) }}</div>
                            </v-col>
                            <v-col cols="6" md="3">
                                <div class="text-caption text-medium-emphasis">Tax / VAT</div>
                                <div class="text-body-1">{{ money(invoice.tax_amount, invoice.currency) }}</div>
                            </v-col>
                            <v-col cols="6" md="3">
                                <div class="text-caption text-medium-emphasis">Total</div>
                                <div class="text-h6 font-weight-bold">{{ money(invoice.total_amount, invoice.currency) }}</div>
                            </v-col>
                            <v-col cols="6" md="3">
                                <div class="text-caption text-medium-emphasis">Priority</div>
                                <v-chip size="small" variant="tonal" :style="{ color: PRIORITY_META[invoice.priority]?.color }">
                                    {{ PRIORITY_META[invoice.priority]?.label ?? invoice.priority }}
                                </v-chip>
                            </v-col>
                        </v-row>
                        <template v-if="invoice.description">
                            <v-divider class="my-4" />
                            <div class="text-caption text-medium-emphasis">Description</div>
                            <div class="text-body-2" style="white-space: pre-wrap">{{ invoice.description }}</div>
                        </template>
                    </v-card-text>
                </v-card>

                <v-card class="mb-4">
                    <v-card-title class="text-subtitle-1">Supporting Documents ({{ invoice.documents.length }})</v-card-title>
                    <v-card-text>
                        <div v-if="!invoice.documents.length" class="text-body-2 text-medium-emphasis">No documents attached.</div>
                        <v-list v-else density="comfortable">
                            <v-list-item
                                v-for="doc in invoice.documents"
                                :key="doc.id"
                                :title="doc.original_name"
                                :subtitle="`${fileSize(doc.size)} · uploaded by ${doc.uploader?.name} · ${dateTime(doc.created_at)}`"
                                prepend-icon="mdi-file-outline"
                            >
                                <template #append>
                                    <v-btn icon="mdi-download" variant="text" size="small" @click="download(doc)" />
                                </template>
                            </v-list-item>
                        </v-list>
                    </v-card-text>
                </v-card>

                <v-card>
                    <v-card-title class="text-subtitle-1">Audit Trail</v-card-title>
                    <v-card-text>
                        <v-timeline density="compact" side="end" truncate-line="both">
                            <v-timeline-item
                                v-for="log in invoice.audit_logs"
                                :key="log.id"
                                size="small"
                                dot-color="grey-lighten-2"
                                :icon="auditIcons[log.action] ?? 'mdi-information-outline'"
                                icon-color="grey-darken-2"
                            >
                                <div class="text-body-2">{{ log.description }}</div>
                                <div class="text-caption text-medium-emphasis">
                                    {{ log.user?.name ?? 'System' }} · {{ dateTime(log.created_at) }}
                                </div>
                            </v-timeline-item>
                        </v-timeline>
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" md="4">
                <v-card class="mb-4">
                    <v-card-title class="text-subtitle-1">ERP Posting</v-card-title>
                    <v-card-text>
                        <v-row dense>
                            <v-col v-for="field in [
                                ['Status', null],
                                ['ERP doc no.', invoice.erp_doc_no || '—'],
                                ['Posted on', shortDate(invoice.posting_date)],
                                ['Posted by', invoice.poster?.name || '—'],
                            ]" :key="field[0]" cols="12">
                                <div class="d-flex justify-space-between align-center">
                                    <span class="text-caption text-medium-emphasis">{{ field[0] }}</span>
                                    <StatusChip v-if="field[0] === 'Status'" :status="invoice.status" size="small" />
                                    <span v-else class="text-body-2 font-weight-medium">{{ field[1] }}</span>
                                </div>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>

                <v-card v-if="pr">
                    <v-card-title class="text-subtitle-1 d-flex align-center">
                        Payment Request
                        <v-spacer />
                        <v-btn variant="text" size="small" color="primary" :to="`/payment-requests/${pr.id}`">{{ pr.reference_no }}</v-btn>
                    </v-card-title>
                    <v-card-text>
                        <div class="d-flex align-center ga-2 mb-3">
                            <StatusChip :status="pr.status" size="small" />
                        </div>
                        <v-timeline v-if="pr.approvals?.length" density="compact" side="end" truncate-line="both">
                            <v-timeline-item
                                v-for="step in pr.approvals"
                                :key="step.id"
                                size="small"
                                :icon="approvalIcon(step.status)"
                                :dot-color="step.sequence === pr.current_stage && step.status === 'pending' ? '#eda100' : 'grey-lighten-3'"
                                :icon-color="approvalColor(step.status)"
                            >
                                <div class="text-body-2 font-weight-medium">
                                    {{ step.is_adhoc ? 'Additional' : `Stage ${step.sequence}` }} — {{ step.label }}
                                </div>
                                <div class="text-caption text-medium-emphasis">
                                    <template v-if="step.status === 'pending'">
                                        {{ step.approver ? `Assigned to ${step.approver.name}` : 'Awaiting decision' }}
                                    </template>
                                    <template v-else>
                                        {{ step.status === 'approved' ? 'Approved' : 'Rejected' }} by {{ step.approver?.name }} · {{ dateTime(step.acted_at) }}
                                    </template>
                                </div>
                            </v-timeline-item>
                        </v-timeline>
                    </v-card-text>
                </v-card>
                <v-card v-else>
                    <v-card-text class="text-body-2 text-medium-emphasis">
                        Not yet in a payment cycle. Once posted, Finance can include this invoice in a payment request.
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-dialog v-model="dialog.show" max-width="480">
            <v-card>
                <v-card-title>{{ dialogTitle }}</v-card-title>
                <v-card-text>
                    <template v-if="dialog.kind === 'post'">
                        <v-text-field v-model="dialog.erp_doc_no" label="ERP document number *" autofocus />
                        <v-text-field v-model="dialog.posting_date" label="Posting date (defaults to today)" type="date" />
                    </template>
                    <v-textarea v-else-if="dialog.kind === 'query'" v-model="dialog.finance_remarks" label="Query / remarks to the department *" rows="3" autofocus />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="dialog.show = false">Cancel</v-btn>
                    <v-btn color="primary" variant="flat" :loading="acting" @click="confirmDialog">Confirm</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
