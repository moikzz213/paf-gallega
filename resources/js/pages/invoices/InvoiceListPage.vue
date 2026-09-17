<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import api, { errorMessage } from '../../services/api';
import { money, shortDate, statusLabel, PRIORITY_META } from '../../utils/format';
import { useMetaStore } from '../../stores/meta';
import { useAuthStore } from '../../stores/auth';
import { useNotifyStore } from '../../stores/notify';
import StatusChip from '../../components/StatusChip.vue';

const meta = useMetaStore();
const auth = useAuthStore();
const notify = useNotifyStore();
const loading = ref(false);
const acting = ref(false);
const items = ref([]);
const total = ref(0);

const filters = reactive({
    q: '',
    status: [],
    department: null,
    priority: null,
    date_from: null,
    date_to: null,
});

const options = reactive({ page: 1, itemsPerPage: 15, sortBy: [{ key: 'submitted_at', order: 'desc' }] });

const dialog = ref({ show: false, kind: 'post', invoice: null, erp_doc_no: '', posting_date: '', finance_remarks: '' });

const headers = [
    { title: 'Submitted', key: 'submitted_at' },
    { title: 'Vendor / Invoice #', key: 'vendor_name', sortable: false },
    { title: 'Dept', key: 'department', sortable: false },
    { title: 'Total', key: 'total_amount', align: 'end' },
    { title: 'Posted', key: 'posting_date', sortable: false },
    { title: 'Status', key: 'status', sortable: false },
    { title: 'O/S Days', key: 'aging', sortable: false },
    { title: 'Priority', key: 'priority' },
    { title: '', key: 'actions', align: 'end', sortable: false },
];

const statusOptions = () => (meta.statuses ?? []).map((s) => ({ value: s, title: statusLabel(s) }));

function osDays(date) {
    if (!date) return 0;
    return Math.max(0, Math.floor((Date.now() - new Date(date)) / 86400000));
}

function agingColor(d) {
    return d <= 7 ? '#008300' : d <= 14 ? '#d97706' : '#dc2626';
}

async function load() {
    loading.value = true;
    try {
        const sort = options.sortBy?.[0];
        const { data } = await api.get('/invoices', {
            params: {
                page: options.page,
                per_page: options.itemsPerPage,
                q: filters.q || undefined,
                status: filters.status.length ? filters.status.join(',') : undefined,
                department: filters.department || undefined,
                priority: filters.priority || undefined,
                date_from: filters.date_from || undefined,
                date_to: filters.date_to || undefined,
                sort: sort?.key || 'submitted_at',
                dir: sort?.order || 'desc',
            },
        });
        items.value = data.data;
        total.value = data.total;
    } finally {
        loading.value = false;
    }
}

let debounce = null;
watch(filters, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        options.page = 1;
        load();
    }, 350);
});

onMounted(() => meta.load());

function openDialog(invoice, kind) {
    dialog.value = { show: true, kind, invoice, erp_doc_no: '', posting_date: '', finance_remarks: '' };
}

async function confirmDialog() {
    const { kind, invoice, erp_doc_no, posting_date, finance_remarks } = dialog.value;
    acting.value = true;
    try {
        if (kind === 'post') {
            if (!erp_doc_no.trim()) throw { response: { data: { message: 'ERP document number is required.' } } };
            await api.post(`/invoices/${invoice.id}/post`, { erp_doc_no, posting_date: posting_date || undefined });
            notify.success(`${invoice.reference_no} posted in ERP.`);
        } else {
            if (!finance_remarks.trim()) throw { response: { data: { message: 'A query note is required.' } } };
            await api.post(`/invoices/${invoice.id}/query`, { finance_remarks });
            notify.success(`Query raised on ${invoice.reference_no}.`);
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
        <div class="d-flex align-center mb-6">
            <div>
                <h1 class="text-h5 font-weight-bold">Invoice Log</h1>
                <div class="text-body-2 text-medium-emphasis">Date-wise register — Finance posts invoices to ERP or raises a query</div>
            </div>
            <v-spacer />
            <v-btn color="primary" prepend-icon="mdi-plus" to="/invoices/new">Submit Invoice</v-btn>
        </div>

        <v-card class="mb-4">
            <v-card-text>
                <v-row dense>
                    <v-col cols="12" md="4">
                        <v-text-field
                            v-model="filters.q"
                            label="Search reference, vendor, invoice #"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                            hide-details
                        />
                    </v-col>
                    <v-col cols="12" sm="6" md="3">
                        <v-select
                            v-model="filters.status"
                            :items="statusOptions()"
                            label="Status"
                            multiple
                            chips
                            closable-chips
                            clearable
                            hide-details
                        />
                    </v-col>
                    <v-col cols="12" sm="6" md="2">
                        <v-select
                            v-model="filters.department"
                            :items="meta.departments"
                            label="Department"
                            clearable
                            hide-details
                        />
                    </v-col>
                    <v-col cols="12" sm="6" md="2">
                        <v-select
                            v-model="filters.priority"
                            :items="meta.priorities.map(p => ({ value: p, title: PRIORITY_META[p]?.label ?? p }))"
                            label="Priority"
                            clearable
                            hide-details
                        />
                    </v-col>
                    <v-col cols="6" md="1.5">
                        <v-text-field v-model="filters.date_from" label="From" type="date" hide-details />
                    </v-col>
                    <v-col cols="6" md="1.5">
                        <v-text-field v-model="filters.date_to" label="To" type="date" hide-details />
                    </v-col>
                </v-row>
            </v-card-text>
        </v-card>

        <v-card>
            <v-data-table-server
                v-model:page="options.page"
                v-model:items-per-page="options.itemsPerPage"
                v-model:sort-by="options.sortBy"
                :headers="headers"
                :items="items"
                :items-length="total"
                :loading="loading"
                density="comfortable"
                @update:options="load"
            >
                <template #item.submitted_at="{ item }">
                    {{ shortDate(item.submitted_at) }}
                </template>
                <template #item.vendor_name="{ item }">
                    <router-link :to="`/invoices/${item.id}`" class="text-primary text-decoration-none font-weight-medium">
                        {{ item.vendor_name }}{{ item.vendor?.vendor_code ? ` (${item.vendor.vendor_code})` : '' }}
                    </router-link>
                    <div class="text-caption text-medium-emphasis">{{ item.invoice_no }} · {{ item.reference_no }}</div>
                </template>
                <template #item.total_amount="{ item }">
                    <span style="font-variant-numeric: tabular-nums">{{ money(item.total_amount, item.currency) }}</span>
                </template>
                <template #item.posting_date="{ item }">
                    {{ shortDate(item.posting_date) }}
                    <div v-if="item.erp_doc_no" class="text-caption text-medium-emphasis">{{ item.erp_doc_no }}</div>
                </template>
                <template #item.status="{ item }">
                    <StatusChip :status="item.status" size="small" />
                    <div v-if="item.payment_status && item.payment_status !== 'not_initiated'" class="mt-1">
                        <StatusChip :status="item.payment_status" size="x-small" />
                    </div>
                </template>
                <template #item.aging="{ item }">
                    <span v-if="item.payment_status === 'paid'" class="text-medium-emphasis">—</span>
                    <span v-else class="font-weight-bold" :style="{ color: agingColor(osDays(item.submitted_at)) }">
                        {{ osDays(item.submitted_at) }} d
                    </span>
                </template>
                <template #item.priority="{ item }">
                    <v-chip size="small" variant="tonal" :style="{ color: PRIORITY_META[item.priority]?.color }">
                        {{ PRIORITY_META[item.priority]?.label ?? item.priority }}
                    </v-chip>
                </template>
                <template #item.actions="{ item }">
                    <template v-if="auth.canProcessPayments && ['submitted', 'query_raised'].includes(item.status)">
                        <v-btn color="success" size="small" variant="tonal" class="mr-2" @click="openDialog(item, 'post')">Post</v-btn>
                        <v-btn color="warning" size="small" variant="text" @click="openDialog(item, 'query')">Query</v-btn>
                    </template>
                </template>
            </v-data-table-server>
        </v-card>

        <v-dialog v-model="dialog.show" max-width="480">
            <v-card>
                <v-card-title>
                    {{ dialog.kind === 'post' ? 'Post to ERP' : 'Raise Query' }} — {{ dialog.invoice?.reference_no }}
                </v-card-title>
                <v-card-text>
                    <div class="text-body-2 mb-3">
                        {{ dialog.invoice?.vendor_name }} — <strong>{{ money(dialog.invoice?.total_amount, dialog.invoice?.currency) }}</strong>
                    </div>
                    <template v-if="dialog.kind === 'post'">
                        <v-text-field v-model="dialog.erp_doc_no" label="ERP document number *" autofocus />
                        <v-text-field v-model="dialog.posting_date" label="Posting date (defaults to today)" type="date" />
                    </template>
                    <v-textarea v-else v-model="dialog.finance_remarks" label="Query / remarks to the department *" rows="3" autofocus />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="dialog.show = false">Cancel</v-btn>
                    <v-btn :color="dialog.kind === 'post' ? 'success' : 'warning'" variant="flat" :loading="acting" @click="confirmDialog">Confirm</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
