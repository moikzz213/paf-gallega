<script setup>
import { reactive, ref, watch } from 'vue';
import api, { errorMessage } from '../services/api';
import { money, dateTime, shortDate, PRIORITY_META } from '../utils/format';
import { useNotifyStore } from '../stores/notify';

const notify = useNotifyStore();
const tab = ref('approved');
const loading = ref(false);
const acting = ref(false);
const items = ref([]);
const total = ref(0);
const options = reactive({ page: 1, itemsPerPage: 15 });

const dialog = ref({ show: false, kind: 'schedule', invoice: null, scheduled_date: '', payment_reference: '' });

const headers = [
    { title: 'Reference', key: 'reference_no', sortable: false },
    { title: 'Vendor', key: 'vendor_name', sortable: false },
    { title: 'Total', key: 'total_amount', align: 'end', sortable: false },
    { title: 'Priority', key: 'priority', sortable: false },
    { title: 'Timeline', key: 'timeline', sortable: false },
    { title: '', key: 'actions', align: 'end', sortable: false },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/payments/queue', {
            params: { status: tab.value, page: options.page, per_page: options.itemsPerPage },
        });
        items.value = data.data;
        total.value = data.total;
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        loading.value = false;
    }
}

watch(tab, () => {
    options.page = 1;
    load();
});

function openDialog(invoice, kind) {
    dialog.value = { show: true, kind, invoice, scheduled_date: invoice.scheduled_date ?? '', payment_reference: '' };
}

async function confirmDialog() {
    const { kind, invoice, scheduled_date, payment_reference } = dialog.value;
    acting.value = true;
    try {
        if (kind === 'schedule') {
            if (!scheduled_date) throw { response: { data: { message: 'Pick a payment date.' } } };
            await api.post(`/invoices/${invoice.id}/schedule`, { scheduled_date });
            notify.success(`Payment for ${invoice.reference_no} scheduled.`);
        } else {
            if (!payment_reference.trim()) throw { response: { data: { message: 'A payment reference is required.' } } };
            await api.post(`/invoices/${invoice.id}/mark-paid`, { payment_reference });
            notify.success(`${invoice.reference_no} marked as paid.`);
        }
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
            <h1 class="text-h5 font-weight-bold">Payments</h1>
            <div class="text-body-2 text-medium-emphasis">Process approved requests through to payment</div>
        </div>

        <v-card>
            <v-tabs v-model="tab" color="primary">
                <v-tab value="approved">To Schedule</v-tab>
                <v-tab value="scheduled">Scheduled</v-tab>
                <v-tab value="paid">Paid</v-tab>
            </v-tabs>
            <v-divider />
            <v-data-table-server
                v-model:page="options.page"
                v-model:items-per-page="options.itemsPerPage"
                :headers="headers"
                :items="items"
                :items-length="total"
                :loading="loading"
                density="comfortable"
                @update:options="load"
            >
                <template #item.reference_no="{ item }">
                    <router-link :to="`/invoices/${item.id}`" class="text-primary text-decoration-none font-weight-medium">
                        {{ item.reference_no }}
                    </router-link>
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
                <template #item.timeline="{ item }">
                    <template v-if="tab === 'approved'">Approved {{ dateTime(item.approved_at) }}</template>
                    <template v-else-if="tab === 'scheduled'">Scheduled for {{ shortDate(item.scheduled_date) }}</template>
                    <template v-else>
                        Paid {{ dateTime(item.paid_at) }}
                        <div class="text-caption text-medium-emphasis">ref {{ item.payment_reference }}</div>
                    </template>
                </template>
                <template #item.actions="{ item }">
                    <template v-if="tab !== 'paid'">
                        <v-btn
                            v-if="tab === 'approved'"
                            color="info" size="small" variant="tonal" class="mr-2"
                            @click="openDialog(item, 'schedule')"
                        >
                            Schedule
                        </v-btn>
                        <v-btn color="success" size="small" variant="flat" @click="openDialog(item, 'pay')">
                            Mark Paid
                        </v-btn>
                    </template>
                </template>
            </v-data-table-server>
        </v-card>

        <v-dialog v-model="dialog.show" max-width="480">
            <v-card>
                <v-card-title>
                    {{ dialog.kind === 'schedule' ? 'Schedule Payment' : 'Mark as Paid' }} — {{ dialog.invoice?.reference_no }}
                </v-card-title>
                <v-card-text>
                    <div class="text-body-2 mb-3">
                        {{ dialog.invoice?.vendor_name }} —
                        <strong>{{ money(dialog.invoice?.total_amount, dialog.invoice?.currency) }}</strong>
                    </div>
                    <v-text-field
                        v-if="dialog.kind === 'schedule'"
                        v-model="dialog.scheduled_date"
                        label="Payment date"
                        type="date"
                        autofocus
                    />
                    <v-text-field
                        v-else
                        v-model="dialog.payment_reference"
                        label="Payment reference / transaction #"
                        autofocus
                    />
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
