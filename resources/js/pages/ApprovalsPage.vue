<script setup>
import { reactive, ref } from 'vue';
import api, { errorMessage } from '../services/api';
import { money, dateTime, PRIORITY_META } from '../utils/format';
import { useNotifyStore } from '../stores/notify';

const notify = useNotifyStore();
const loading = ref(false);
const acting = ref(false);
const items = ref([]);
const total = ref(0);
const options = reactive({ page: 1, itemsPerPage: 15 });

const dialog = ref({ show: false, kind: 'approve', invoice: null, comments: '' });

const headers = [
    { title: 'Reference', key: 'reference_no', sortable: false },
    { title: 'Vendor', key: 'vendor_name', sortable: false },
    { title: 'Requested By', key: 'submitter', sortable: false },
    { title: 'Total', key: 'total_amount', align: 'end', sortable: false },
    { title: 'Priority', key: 'priority', sortable: false },
    { title: 'Level', key: 'current_level', sortable: false },
    { title: 'Submitted', key: 'submitted_at', sortable: false },
    { title: '', key: 'actions', align: 'end', sortable: false },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/approvals/pending', {
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

function openDialog(invoice, kind) {
    dialog.value = { show: true, kind, invoice, comments: '' };
}

async function confirmDialog() {
    const { kind, invoice, comments } = dialog.value;
    if (kind === 'reject' && !comments.trim()) {
        notify.error('A reason is required to reject.');
        return;
    }
    acting.value = true;
    try {
        await api.post(`/invoices/${invoice.id}/${kind}`, { comments });
        notify.success(`${invoice.reference_no} ${kind === 'approve' ? 'approved' : 'rejected'}.`);
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
            <div class="text-body-2 text-medium-emphasis">Requests waiting for your decision, urgent first</div>
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
                    <router-link :to="`/invoices/${item.id}`" class="text-primary text-decoration-none font-weight-medium">
                        {{ item.reference_no }}
                    </router-link>
                </template>
                <template #item.submitter="{ item }">
                    {{ item.submitter?.name }}
                    <div class="text-caption text-medium-emphasis">{{ item.department }}</div>
                </template>
                <template #item.total_amount="{ item }">
                    <span style="font-variant-numeric: tabular-nums" class="font-weight-medium">
                        {{ money(item.total_amount, item.currency) }}
                    </span>
                </template>
                <template #item.priority="{ item }">
                    <v-chip size="small" variant="tonal" :style="{ color: PRIORITY_META[item.priority]?.color }">
                        {{ PRIORITY_META[item.priority]?.label ?? item.priority }}
                    </v-chip>
                </template>
                <template #item.current_level="{ item }">
                    L{{ item.current_level }}
                </template>
                <template #item.submitted_at="{ item }">
                    {{ dateTime(item.submitted_at) }}
                </template>
                <template #item.actions="{ item }">
                    <v-btn color="success" size="small" variant="flat" class="mr-2" @click="openDialog(item, 'approve')">
                        Approve
                    </v-btn>
                    <v-btn color="error" size="small" variant="tonal" @click="openDialog(item, 'reject')">
                        Reject
                    </v-btn>
                </template>
            </v-data-table-server>
        </v-card>

        <v-dialog v-model="dialog.show" max-width="480">
            <v-card>
                <v-card-title>
                    {{ dialog.kind === 'approve' ? 'Approve' : 'Reject' }} {{ dialog.invoice?.reference_no }}
                </v-card-title>
                <v-card-text>
                    <div class="text-body-2 mb-3">
                        {{ dialog.invoice?.vendor_name }} —
                        <strong>{{ money(dialog.invoice?.total_amount, dialog.invoice?.currency) }}</strong>
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
                    <v-btn
                        :color="dialog.kind === 'approve' ? 'success' : 'error'"
                        variant="flat"
                        :loading="acting"
                        @click="confirmDialog"
                    >
                        {{ dialog.kind === 'approve' ? 'Approve' : 'Reject' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
