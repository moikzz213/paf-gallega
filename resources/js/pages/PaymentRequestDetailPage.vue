<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import api, { errorMessage } from '../services/api';
import { dateTime, money, shortDate } from '../utils/format';
import { useAuthStore } from '../stores/auth';
import { useNotifyStore } from '../stores/notify';
import StatusChip from '../components/StatusChip.vue';

const props = defineProps({ id: { type: String, required: true } });

const auth = useAuthStore();
const notify = useNotifyStore();
const router = useRouter();

const pr = ref(null);
const loading = ref(true);
const acting = ref(false);
const dialog = ref({ show: false, kind: null, comments: '', payment_reference: '' });

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get(`/payment-requests/${props.id}`);
        pr.value = data;
    } catch (e) {
        notify.error(errorMessage(e));
        router.replace('/payment-requests');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

const currentStep = computed(() => pr.value?.approvals?.find((s) => s.sequence === pr.value?.current_stage));

const canAct = computed(() => {
    if (pr.value?.status !== 'in_approval') return false;
    if (auth.isAdmin) return true;
    return currentStep.value?.approver_id === auth.user?.id;
});
const canPay = computed(() => auth.canProcessPayments && pr.value?.status === 'approved');

function openDialog(kind) {
    dialog.value = { show: true, kind, comments: '', payment_reference: '' };
}

const dialogTitle = computed(() => ({
    approve: 'Approve Payment Request',
    reject: 'Reject Payment Request',
    pay: 'Mark as Paid',
}[dialog.value.kind]));

async function runAction(kind, payload = {}) {
    acting.value = true;
    try {
        const urls = {
            approve: `/payment-requests/${props.id}/approve`,
            reject: `/payment-requests/${props.id}/reject`,
            pay: `/payment-requests/${props.id}/mark-paid`,
        };
        await api.post(urls[kind], payload);
        notify.success({ approve: 'Stage approved.', reject: 'Payment request rejected.', pay: 'Payment recorded.' }[kind]);
        dialog.value.show = false;
        await load();
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        acting.value = false;
    }
}

function confirmDialog() {
    const { kind, comments, payment_reference } = dialog.value;
    if (kind === 'approve') return runAction('approve', { comments });
    if (kind === 'reject') {
        if (!comments.trim()) return notify.error('A reason is required to reject.');
        return runAction('reject', { comments });
    }
    if (kind === 'pay') {
        if (!payment_reference.trim()) return notify.error('A payment reference is required.');
        return runAction('pay', { payment_reference });
    }
}

function approvalIcon(status) {
    return { approved: 'mdi-check-circle', rejected: 'mdi-close-circle', pending: 'mdi-circle-outline' }[status];
}
function approvalColor(status) {
    return { approved: '#1baf7a', rejected: '#e34948', pending: '#898781' }[status];
}
</script>

<template>
    <div v-if="loading" class="d-flex justify-center py-16">
        <v-progress-circular indeterminate color="primary" size="48" />
    </div>

    <div v-else-if="pr">
        <div class="d-flex align-center flex-wrap mb-6 ga-2">
            <v-btn icon="mdi-arrow-left" variant="text" @click="router.back()" />
            <div class="mr-2">
                <h1 class="text-h5 font-weight-bold d-flex align-center ga-3">
                    {{ pr.reference_no }}
                    <StatusChip :status="pr.status" size="default" />
                </h1>
                <div class="text-body-2 text-medium-emphasis">
                    {{ pr.invoices?.length }} invoice(s) · <strong>{{ money(pr.total_amount) }}</strong> · created by {{ pr.creator?.name }}
                </div>
            </div>
            <v-spacer />

            <v-btn v-if="canAct" color="success" prepend-icon="mdi-check" @click="openDialog('approve')">Approve</v-btn>
            <v-btn v-if="canAct" color="error" variant="tonal" prepend-icon="mdi-close" @click="openDialog('reject')">Reject</v-btn>
            <v-btn v-if="canPay" color="success" prepend-icon="mdi-cash-check" @click="openDialog('pay')">Mark Paid</v-btn>
        </div>

        <v-alert v-if="pr.status === 'rejected'" type="error" variant="tonal" class="mb-4" icon="mdi-close-circle-outline">
            <strong>Rejected:</strong> {{ pr.rejection_reason }} — invoices were returned to the pool for re-initiation.
        </v-alert>
        <v-alert v-else-if="pr.status === 'approved'" type="success" variant="tonal" class="mb-4" icon="mdi-check-circle-outline">
            Fully approved — released for payment.
        </v-alert>
        <v-alert v-else-if="pr.status === 'paid'" type="success" variant="tonal" class="mb-4" icon="mdi-cash-check">
            Paid on {{ dateTime(pr.paid_at) }} · reference {{ pr.payment_reference }}
        </v-alert>

        <v-row>
            <v-col cols="12" md="7">
                <v-card class="mb-4">
                    <v-card-title class="text-subtitle-1">Invoices in this request</v-card-title>
                    <v-data-table
                        :headers="[
                            { title: 'Reference', key: 'reference_no', sortable: false },
                            { title: 'Vendor / Invoice #', key: 'vendor_name', sortable: false },
                            { title: 'Total', key: 'total_amount', align: 'end', sortable: false },
                        ]"
                        :items="pr.invoices"
                        density="compact"
                        hide-default-footer
                        :items-per-page="-1"
                    >
                        <template #item.reference_no="{ item }">
                            <router-link :to="`/invoices/${item.id}`" class="text-primary text-decoration-none">{{ item.reference_no }}</router-link>
                        </template>
                        <template #item.vendor_name="{ item }">
                            {{ item.vendor_name }}
                            <div class="text-caption text-medium-emphasis">{{ item.invoice_no }}</div>
                        </template>
                        <template #item.total_amount="{ item }">{{ money(item.total_amount, item.currency) }}</template>
                    </v-data-table>
                    <v-divider />
                    <div class="d-flex justify-space-between pa-4">
                        <span class="font-weight-medium">Total payment amount</span>
                        <span class="font-weight-bold">{{ money(pr.total_amount) }}</span>
                    </div>
                </v-card>

                <v-card v-if="pr.audit_logs?.length">
                    <v-card-title class="text-subtitle-1">Audit Trail</v-card-title>
                    <v-card-text>
                        <v-timeline density="compact" side="end" truncate-line="both">
                            <v-timeline-item
                                v-for="log in pr.audit_logs"
                                :key="log.id"
                                size="small"
                                dot-color="grey-lighten-2"
                                icon="mdi-information-outline"
                                icon-color="grey-darken-2"
                            >
                                <div class="text-body-2">{{ log.description }}</div>
                                <div class="text-caption text-medium-emphasis">{{ log.user?.name ?? 'System' }} · {{ dateTime(log.created_at) }}</div>
                            </v-timeline-item>
                        </v-timeline>
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" md="5">
                <v-card class="mb-4">
                    <v-card-title class="text-subtitle-1">Approval Chain</v-card-title>
                    <v-card-text>
                        <v-timeline density="compact" side="end" truncate-line="both">
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
                                    <v-chip v-if="step.is_adhoc" size="x-small" variant="tonal" class="ml-1">ad-hoc</v-chip>
                                    <v-chip
                                        v-if="step.sequence === pr.current_stage && step.status === 'pending'"
                                        size="x-small" variant="tonal" style="color: #b87a00" class="ml-1"
                                    >current</v-chip>
                                </div>
                                <div class="text-caption text-medium-emphasis">
                                    <template v-if="step.status === 'pending'">
                                        {{ step.approver ? `Assigned to ${step.approver.name}` : 'Awaiting decision' }}
                                    </template>
                                    <template v-else>
                                        {{ step.status === 'approved' ? 'Approved' : 'Rejected' }} by {{ step.approver?.name }} · {{ dateTime(step.acted_at) }}
                                    </template>
                                </div>
                                <div v-if="step.comments" class="text-caption font-italic mt-1">“{{ step.comments }}”</div>
                            </v-timeline-item>
                        </v-timeline>
                    </v-card-text>
                </v-card>

                <v-card>
                    <v-card-title class="text-subtitle-1">Details</v-card-title>
                    <v-card-text>
                        <v-row dense>
                            <v-col v-for="field in [
                                ['Created', dateTime(pr.created_at)],
                                ['Sent for approval', dateTime(pr.sent_at)],
                                ['Approved', dateTime(pr.approved_at)],
                                ['Paid', dateTime(pr.paid_at)],
                                ['Payment ref', pr.payment_reference || '—'],
                                ['Processed by', pr.payer?.name || '—'],
                            ]" :key="field[0]" cols="12">
                                <div class="d-flex justify-space-between">
                                    <span class="text-caption text-medium-emphasis">{{ field[0] }}</span>
                                    <span class="text-body-2 font-weight-medium">{{ field[1] }}</span>
                                </div>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-dialog v-model="dialog.show" max-width="480">
            <v-card>
                <v-card-title>{{ dialogTitle }}</v-card-title>
                <v-card-text>
                    <template v-if="dialog.kind === 'approve' || dialog.kind === 'reject'">
                        <v-textarea
                            v-model="dialog.comments"
                            :label="dialog.kind === 'reject' ? 'Reason (required)' : 'Comments (optional)'"
                            rows="3"
                            autofocus
                        />
                    </template>
                    <template v-else-if="dialog.kind === 'pay'">
                        <v-text-field v-model="dialog.payment_reference" label="Payment reference / transaction #" autofocus />
                    </template>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="dialog.show = false">Cancel</v-btn>
                    <v-btn :color="dialog.kind === 'reject' ? 'error' : 'primary'" variant="flat" :loading="acting" @click="confirmDialog">Confirm</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
