<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import api, { errorMessage } from '../services/api';
import { invoicesCurrency, money, dateTime } from '../utils/format';
import { useNotifyStore } from '../stores/notify';

const notify = useNotifyStore();
const router = useRouter();
const loading = ref(false);
const acting = ref(false);
const items = ref([]);
const total = ref(0);
const options = reactive({ page: 1, itemsPerPage: 15 });

const dialog = ref({ show: false, kind: 'approve', pr: null, comments: '' });

const dialogInvoiceLines = computed(() => (dialog.value.pr?.invoices ?? []).flatMap((invoice) => {
    const lines = invoice.items?.length ? invoice.items : [null];

    return lines.map((line, index) => ({
        id: line?.id ?? `${invoice.id}-summary-${index}`,
        invoice,
        job_no: line?.job_no || '—',
        customer_name: line?.customer?.name || '—',
        description: line?.description || invoice.description || '—',
        currency: line?.currency || invoice.currency || '—',
        total_amount: line?.total_amount ?? invoice.total_amount,
    }));
}));

const headers = [
    { title: 'Reference', key: 'reference_no', sortable: false },
    { title: 'Invoices', key: 'invoices', sortable: false },
    { title: 'Total', key: 'total_amount', align: 'end', sortable: false },
    { title: 'Your stage', key: 'stage', sortable: false },
    { title: 'Sent', key: 'sent_at', sortable: false },
    { title: '', key: 'actions', align: 'end', sortable: false },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/payment-requests/pending', {
            params: { page: options.page, per_page: options.itemsPerPage },
        });
        items.value = data.data;
        total.value = data.total;
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function stageLabel(pr) {
    const step = pr.approvals?.find((s) => s.sequence === pr.current_stage);
    return step ? `${step.sequence} — ${step.label}` : '—';
}

function openDialog(pr, kind) {
    dialog.value = { show: true, kind, pr, comments: '' };
}

async function confirmDialog() {
    const { kind, pr, comments } = dialog.value;
    if (kind === 'reject' && !comments.trim()) {
        notify.error('A reason is required to reject.');
        return;
    }
    acting.value = true;
    try {
        await api.post(`/payment-requests/${pr.id}/${kind}`, { comments });
        notify.success(`${pr.reference_no} ${kind === 'approve' ? 'approved' : 'rejected'}.`);
        dialog.value.show = false;
        await load();
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        acting.value = false;
    }
}
</script>

<template>
    <div>
        <div class="mb-6">
            <h1 class="text-h5 font-weight-bold">Approvals</h1>
            <div class="text-body-2 text-medium-emphasis">Payment requests waiting for your decision</div>
        </div>

        <v-card>
            <v-data-table-server
                v-model:page="options.page"
                v-model:items-per-page="options.itemsPerPage"
                :headers="headers"
                :items="items"
                :items-length="total"
                :loading="loading"
                density="comfortable"
                no-data-text="Nothing waiting for your approval 🎉"
                @update:options="load"
            >
                <template #item.reference_no="{ item }">
                    <router-link :to="`/payment-requests/${item.id}`" class="text-primary text-decoration-none font-weight-medium">
                        {{ item.reference_no }}
                    </router-link>
                    <div class="text-caption text-medium-emphasis">by {{ item.creator?.name }}</div>
                </template>
                <template #item.invoices="{ item }">{{ item.invoices?.length ?? 0 }} invoice(s)</template>
                <template #item.total_amount="{ item }">
                    <span style="font-variant-numeric: tabular-nums" class="font-weight-medium">
                        {{ money(item.total_amount, invoicesCurrency(item.invoices)) }}
                    </span>
                </template>
                <template #item.stage="{ item }">{{ stageLabel(item) }}</template>
                <template #item.sent_at="{ item }">{{ dateTime(item.sent_at) }}</template>
                <template #item.actions="{ item }">
                    <v-btn color="success" size="small" variant="flat" class="mr-2" @click="openDialog(item, 'approve')">Approve</v-btn>
                    <v-btn color="error" size="small" variant="tonal" @click="openDialog(item, 'reject')">Reject</v-btn>
                </template>
            </v-data-table-server>
        </v-card>

        <v-dialog v-model="dialog.show" max-width="1100">
            <v-card>
                <v-card-title>
                    {{ dialog.kind === 'approve' ? 'Approve' : 'Reject' }} {{ dialog.pr?.reference_no }}
                </v-card-title>
                <v-card-text>
                    <div class="text-body-2 mb-3">
                        {{ dialog.pr?.invoices?.length }} invoice(s) —
                        <strong>{{ money(dialog.pr?.total_amount, invoicesCurrency(dialog.pr?.invoices)) }}</strong>
                    </div>
                    <div class="overflow-x-auto mb-4">
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Invoice Submitted By</th>
                                    <th>Job No.</th>
                                    <th>Customer</th>
                                    <th>Description</th>
                                    <th class="text-right">Line Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="line in dialogInvoiceLines" :key="line.id">
                                    <td>{{ line.invoice.reference_no }}</td>
                                    <td>{{ line.invoice.submitter?.name || '—' }}</td>
                                    <td>{{ line.job_no }}</td>
                                    <td>{{ line.customer_name }}</td>
                                    <td>{{ line.description }}</td>
                                    <td class="text-right">{{ money(line.total_amount, line.currency) }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </div>
                    <v-textarea
                        v-model="dialog.comments"
                        :label="dialog.kind === 'reject' ? 'Reason (required)' : 'Comments (optional)'"
                        rows="3"
                        autofocus
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="dialog.show = false">Cancel</v-btn>
                    <v-btn :color="dialog.kind === 'approve' ? 'success' : 'error'" variant="flat" :loading="acting" @click="confirmDialog">
                        {{ dialog.kind === 'approve' ? 'Approve' : 'Reject' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
