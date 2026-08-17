<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import api from '../services/api';
import { money, shortDate, STATUS_META, statusLabel } from '../utils/format';
import { useMetaStore } from '../stores/meta';
import StatusChip from '../components/StatusChip.vue';

const meta = useMetaStore();
const loading = ref(false);
const summary = ref([]);
const totals = ref({ count: 0, amount: 0 });
const rows = ref([]);
const rowsTotal = ref(0);
const options = reactive({ page: 1, itemsPerPage: 25 });

const filters = reactive({
    status: [],
    department: null,
    business_unit: null,
    vendor: '',
    date_from: null,
    date_to: null,
});

const statusOptions = Object.entries(STATUS_META).map(([value, m]) => ({ value, title: m.label }));

const headers = [
    { title: 'Reference', key: 'reference_no', sortable: false },
    { title: 'Vendor', key: 'vendor_name', sortable: false },
    { title: 'Invoice #', key: 'invoice_no', sortable: false },
    { title: 'Job No', key: 'job_no', sortable: false },
    { title: 'Date', key: 'invoice_date', sortable: false },
    { title: 'Business Unit', key: 'business_unit', sortable: false },
    { title: 'Department', key: 'department', sortable: false },
    { title: 'Total', key: 'total_amount', align: 'end', sortable: false },
    { title: 'Status', key: 'status', sortable: false },
];

// Job numbers sit on the invoice lines, and a line may carry none.
function jobNumbers(invoice) {
    const numbers = [...new Set((invoice.items ?? []).map((i) => i?.job_no).filter((j) => j && String(j).trim() !== ''))];
    return numbers.length ? numbers.join(', ') : '—';
}

function params() {
    return {
        status: filters.status.length ? filters.status.join(',') : undefined,
        department: filters.department || undefined,
        business_unit: filters.business_unit || undefined,
        vendor: filters.vendor || undefined,
        date_from: filters.date_from || undefined,
        date_to: filters.date_to || undefined,
    };
}

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/reports', {
            params: { ...params(), page: options.page, per_page: options.itemsPerPage },
        });
        summary.value = data.summary;
        totals.value = data.totals;
        rows.value = data.rows.data;
        rowsTotal.value = data.rows.total;
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

function exportExcel() {
    const query = new URLSearchParams(
        Object.entries(params()).filter(([, v]) => v !== undefined)
    ).toString();
    window.open(`/api/reports/export${query ? `?${query}` : ''}`, '_blank');
}

const summaryCards = computed(() =>
    summary.value.map((s) => ({
        status: s.status,
        label: statusLabel(s.status),
        count: s.count,
        amount: Number(s.amount ?? 0),
        color: STATUS_META[s.status]?.color ?? '#898781',
    }))
);

onMounted(() => meta.load());
</script>

<template>
    <div>
        <div class="d-flex align-center mb-6">
            <div>
                <h1 class="text-h5 font-weight-bold">Reports</h1>
                <div class="text-body-2 text-medium-emphasis">Filter, analyse and export payment request data</div>
            </div>
            <v-spacer />
            <v-btn color="success" prepend-icon="mdi-microsoft-excel" @click="exportExcel">Export to Excel</v-btn>
        </div>

        <v-card class="mb-4">
            <v-card-text>
                <v-row dense>
                    <v-col cols="12" sm="6" md="3">
                        <v-select v-model="filters.status" :items="statusOptions" label="Status" multiple chips closable-chips clearable hide-details />
                    </v-col>
                    <v-col cols="12" sm="6" md="2">
                        <v-select v-model="filters.department" :items="meta.departments" label="Department" clearable hide-details />
                    </v-col>
                    <v-col cols="12" sm="6" md="2">
                        <v-autocomplete v-model="filters.business_unit" :items="meta.business_units" label="Business Unit" autocomplete="off" clearable hide-details />
                    </v-col>
                    <v-col cols="12" sm="6" md="2">
                        <v-text-field v-model="filters.vendor" label="Vendor" clearable hide-details />
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

        <v-row class="mb-1">
            <v-col cols="12" sm="6" md="3">
                <v-card class="pa-3">
                    <div class="text-h5 font-weight-bold">{{ totals.count }}</div>
                    <div class="text-body-2 text-medium-emphasis">Matching requests</div>
                    <div class="text-caption text-medium-emphasis">{{ money(totals.amount) }}</div>
                </v-card>
            </v-col>
            <v-col v-for="card in summaryCards" :key="card.status" cols="12" sm="6" md="3">
                <v-card class="pa-3">
                    <div class="d-flex align-center">
                        <span class="mr-2" :style="{ width: '10px', height: '10px', borderRadius: '50%', backgroundColor: card.color, display: 'inline-block' }" />
                        <div class="text-h5 font-weight-bold">{{ card.count }}</div>
                    </div>
                    <div class="text-body-2 text-medium-emphasis">{{ card.label }}</div>
                    <div class="text-caption text-medium-emphasis">{{ money(card.amount) }}</div>
                </v-card>
            </v-col>
        </v-row>

        <v-card>
            <v-data-table-server
                v-model:page="options.page"
                v-model:items-per-page="options.itemsPerPage"
                :headers="headers"
                :items="rows"
                :items-length="rowsTotal"
                :loading="loading"
                density="comfortable"
                @update:options="load"
            >
                <template #item.reference_no="{ item }">
                    <router-link :to="`/invoices/${item.id}`" class="text-primary text-decoration-none font-weight-medium">
                        {{ item.reference_no }}
                    </router-link>
                </template>
                <template #item.job_no="{ item }">
                    {{ jobNumbers(item) }}
                </template>
                <template #item.invoice_date="{ item }">
                    {{ shortDate(item.invoice_date) }}
                </template>
                <template #item.total_amount="{ item }">
                    <span style="font-variant-numeric: tabular-nums">{{ money(item.total_amount, item.currency) }}</span>
                </template>
                <template #item.status="{ item }">
                    <StatusChip :status="item.status" />
                </template>
            </v-data-table-server>
        </v-card>
    </div>
</template>
