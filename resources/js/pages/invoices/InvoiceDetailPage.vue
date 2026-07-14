<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import api, { errorMessage } from '../../services/api';
import { dateTime, fileSize, money, shortDate, PRIORITY_META } from '../../utils/format';
import { useAuthStore } from '../../stores/auth';
import { useMetaStore } from '../../stores/meta';
import { useNotifyStore } from '../../stores/notify';
import StatusChip from '../../components/StatusChip.vue';
import ApprovalChainBuilder from '../../components/ApprovalChainBuilder.vue';

const props = defineProps({ id: { type: String, required: true } });

const auth = useAuthStore();
const meta = useMetaStore();
const notify = useNotifyStore();
const router = useRouter();

const invoice = ref(null);
const loading = ref(true);
const acting = ref(false);
const chain = ref({ assignments: {}, adhoc: [], valid: false });

const dialog = ref({ show: false, kind: null, comments: '', scheduled_date: '', payment_reference: '' });

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

function onChainChange(payload) {
    chain.value = payload;
}

onMounted(() => {
    load();
    meta.load();
    meta.loadApprovers();
});

const isOwner = computed(() => invoice.value?.submitted_by === auth.user?.id);

const canEdit = computed(() => (isOwner.value || auth.isAdmin) && ['draft', 'rejected'].includes(invoice.value?.status));
const canSubmit = computed(() => canEdit.value);
const canCancel = computed(() => (isOwner.value || auth.isAdmin) && ['draft', 'pending_approval'].includes(invoice.value?.status));
const canDelete = computed(() => (isOwner.value || auth.isAdmin) && invoice.value?.status === 'draft');

const currentStep = computed(() =>
    invoice.value?.approvals?.find((s) => s.level === invoice.value?.current_level)
);

const canAct = computed(() => {
    if (invoice.value?.status !== 'pending_approval') return false;
    if (auth.isAdmin) return true;
    const step = currentStep.value;
    if (!step) return false;
    if (step.approver_id) return step.approver_id === auth.user?.id;
    // legacy / unassigned fallback: any approver whose level matches
    return auth.isApprover && auth.user?.approval_level === invoice.value?.current_level;
});

const canSchedule = computed(() => auth.canProcessPayments && invoice.value?.status === 'approved');
const canPay = computed(() => auth.canProcessPayments && ['approved', 'scheduled'].includes(invoice.value?.status));

function openDialog(kind) {
    dialog.value = { show: true, kind, comments: '', scheduled_date: '', payment_reference: '' };
}

const dialogTitle = computed(() => ({
    submit: 'Submit for Approval',
    approve: 'Approve Request',
    reject: 'Reject Request',
    schedule: 'Schedule Payment',
    pay: 'Mark as Paid',
}[dialog.value.kind]));

async function runAction(kind, payload = {}) {
    acting.value = true;
    try {
        const urls = {
            submit: `/invoices/${props.id}/submit`,
            cancel: `/invoices/${props.id}/cancel`,
            approve: `/invoices/${props.id}/approve`,
            reject: `/invoices/${props.id}/reject`,
            schedule: `/invoices/${props.id}/schedule`,
            pay: `/invoices/${props.id}/mark-paid`,
        };
        await api.post(urls[kind], payload);
        notify.success({
            submit: 'Request submitted for approval.',
            cancel: 'Request cancelled.',
            approve: 'Request approved.',
            reject: 'Request rejected.',
            schedule: 'Payment scheduled.',
            pay: 'Payment recorded.',
        }[kind]);
        dialog.value.show = false;
        await load();
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        acting.value = false;
    }
}

async function confirmDialog() {
    const { kind, comments, scheduled_date, payment_reference } = dialog.value;
    if (kind === 'submit') {
        if (!chain.value.valid) return notify.error('Assign an approver for every approval level.');
        const approvers = {};
        Object.entries(chain.value.assignments).forEach(([lvl, id]) => {
            if (id) approvers[lvl] = id;
        });
        const adhoc_approvers = chain.value.adhoc
            .filter((s) => s.approver_id)
            .map((s) => ({ approver_id: s.approver_id, label: s.label || null }));
        return runAction('submit', { approvers, adhoc_approvers });
    }
    if (kind === 'approve') return runAction('approve', { comments });
    if (kind === 'reject') {
        if (!comments.trim()) return notify.error('A reason is required to reject.');
        return runAction('reject', { comments });
    }
    if (kind === 'schedule') {
        if (!scheduled_date) return notify.error('Pick a payment date.');
        return runAction('schedule', { scheduled_date });
    }
    if (kind === 'pay') {
        if (!payment_reference.trim()) return notify.error('A payment reference is required.');
        return runAction('pay', { payment_reference });
    }
}

async function deleteDraft() {
    if (!confirm('Delete this draft permanently?')) return;
    try {
        await api.delete(`/invoices/${props.id}`);
        notify.success('Draft deleted.');
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
    created: 'mdi-plus-circle-outline',
    updated: 'mdi-pencil-outline',
    submitted: 'mdi-send',
    approved: 'mdi-thumb-up-outline',
    rejected: 'mdi-thumb-down-outline',
    scheduled: 'mdi-calendar-clock',
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
                </h1>
                <div class="text-body-2 text-medium-emphasis">
                    {{ invoice.vendor_name }} · Invoice {{ invoice.invoice_no }} · Requested by {{ invoice.submitter?.name }}
                </div>
            </div>
            <v-spacer />

            <v-btn v-if="canAct" color="success" prepend-icon="mdi-check" @click="openDialog('approve')">Approve</v-btn>
            <v-btn v-if="canAct" color="error" variant="tonal" prepend-icon="mdi-close" @click="openDialog('reject')">Reject</v-btn>
            <v-btn v-if="canSchedule" color="info" prepend-icon="mdi-calendar-clock" @click="openDialog('schedule')">Schedule</v-btn>
            <v-btn v-if="canPay" color="success" prepend-icon="mdi-cash-check" @click="openDialog('pay')">Mark Paid</v-btn>
            <v-btn v-if="canSubmit" color="primary" prepend-icon="mdi-send" @click="openDialog('submit')">Submit</v-btn>
            <v-btn v-if="canEdit" variant="tonal" prepend-icon="mdi-pencil" :to="`/invoices/${invoice.id}/edit`">Edit</v-btn>
            <v-btn v-if="canCancel" variant="text" color="error" prepend-icon="mdi-cancel" :loading="acting" @click="runAction('cancel')">Cancel</v-btn>
            <v-btn v-if="canDelete" variant="text" color="error" icon="mdi-delete-outline" @click="deleteDraft" />
        </div>

        <v-alert v-if="invoice.status === 'rejected'" type="error" variant="tonal" class="mb-4" icon="mdi-close-circle-outline">
            <strong>Rejected:</strong> {{ invoice.rejection_reason }}
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
                    <v-card-title class="text-subtitle-1">
                        Supporting Documents ({{ invoice.documents.length }})
                    </v-card-title>
                    <v-card-text>
                        <div v-if="!invoice.documents.length" class="text-body-2 text-medium-emphasis">
                            No documents attached.
                        </div>
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
                    <v-card-title class="text-subtitle-1">Approval Chain</v-card-title>
                    <v-card-text>
                        <div v-if="!invoice.approvals.length" class="text-body-2 text-medium-emphasis">
                            Not yet submitted — no approval chain.
                        </div>
                        <v-timeline v-else density="compact" side="end" truncate-line="both">
                            <v-timeline-item
                                v-for="step in invoice.approvals"
                                :key="step.id"
                                size="small"
                                :icon="approvalIcon(step.status)"
                                :dot-color="step.level === invoice.current_level && step.status === 'pending' ? '#eda100' : 'grey-lighten-3'"
                                :icon-color="approvalColor(step.status)"
                            >
                                <div class="text-body-2 font-weight-medium">
                                    <template v-if="step.is_adhoc">Additional — {{ step.level_name }}</template>
                                    <template v-else>Level {{ step.level }} — {{ step.level_name }}</template>
                                    <v-chip v-if="step.is_adhoc" size="x-small" variant="tonal" class="ml-1">ad-hoc</v-chip>
                                    <v-chip
                                        v-if="step.level === invoice.current_level && step.status === 'pending'"
                                        size="x-small" variant="tonal" style="color: #b87a00" class="ml-1"
                                    >
                                        current
                                    </v-chip>
                                </div>
                                <div class="text-caption text-medium-emphasis">
                                    <template v-if="step.status === 'pending'">
                                        <template v-if="step.approver">Assigned to {{ step.approver.name }}</template>
                                        <template v-else>Awaiting decision</template>
                                    </template>
                                    <template v-else>
                                        {{ step.status === 'approved' ? 'Approved' : 'Rejected' }} by {{ step.approver?.name }}
                                        · {{ dateTime(step.acted_at) }}
                                    </template>
                                </div>
                                <div v-if="step.comments" class="text-caption font-italic mt-1">“{{ step.comments }}”</div>
                            </v-timeline-item>
                        </v-timeline>
                    </v-card-text>
                </v-card>

                <v-card>
                    <v-card-title class="text-subtitle-1">Payment</v-card-title>
                    <v-card-text>
                        <v-row dense>
                            <v-col v-for="field in [
                                ['Method', invoice.payment_method?.replace('_', ' ')],
                                ['Submitted', dateTime(invoice.submitted_at)],
                                ['Approved', dateTime(invoice.approved_at)],
                                ['Scheduled for', shortDate(invoice.scheduled_date)],
                                ['Paid at', dateTime(invoice.paid_at)],
                                ['Payment ref', invoice.payment_reference || '—'],
                                ['Processed by', invoice.payer?.name || '—'],
                            ]" :key="field[0]" cols="12">
                                <div class="d-flex justify-space-between">
                                    <span class="text-caption text-medium-emphasis">{{ field[0] }}</span>
                                    <span class="text-body-2 font-weight-medium text-capitalize">{{ field[1] }}</span>
                                </div>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-dialog v-model="dialog.show" :max-width="dialog.kind === 'submit' ? 760 : 480">
            <v-card>
                <v-card-title>{{ dialogTitle }}</v-card-title>
                <v-card-text>
                    <template v-if="dialog.kind === 'submit'">
                        <ApprovalChainBuilder :total="Number(invoice.total_amount)" @change="onChainChange" />
                    </template>
                    <template v-else-if="dialog.kind === 'approve' || dialog.kind === 'reject'">
                        <v-textarea
                            v-model="dialog.comments"
                            :label="dialog.kind === 'reject' ? 'Reason (required)' : 'Comments (optional)'"
                            rows="3"
                            autofocus
                        />
                    </template>
                    <template v-else-if="dialog.kind === 'schedule'">
                        <v-text-field v-model="dialog.scheduled_date" label="Payment date" type="date" autofocus />
                    </template>
                    <template v-else-if="dialog.kind === 'pay'">
                        <v-text-field v-model="dialog.payment_reference" label="Payment reference / transaction #" autofocus />
                    </template>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="dialog.show = false">Cancel</v-btn>
                    <v-btn
                        :color="dialog.kind === 'reject' ? 'error' : 'primary'"
                        variant="flat"
                        :loading="acting"
                        @click="confirmDialog"
                    >
                        Confirm
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
