<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import api from '../services/api';
import { money, shortDate, STATUS_META, statusLabel } from '../utils/format';
import { useMetaStore } from '../stores/meta';
import StatusChip from '../components/StatusChip.vue';

const meta = useMetaStore();
const loading = ref(false);
const summary = ref([]);
const totals = ref({ count: 0, amounts: [] });
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
    { title: 'Rejected PAFs', key: 'rejected_payment_requests', sortable: false },
    { title: 'Query Raised', key: 'query_raised', sortable: false },
    { title: 'Remarks', key: 'remarks', sortable: false },
];

// Job numbers sit on the invoice lines, and a line may carry none.
function jobNumbers(invoice) {
    const numbers = [...new Set((invoice.items ?? []).map((i) => i?.job_no).filter((j) => j && String(j).trim() !== ''))];
    return numbers.length ? numbers.join(', ') : '—';
}

// Every query Finance raised, oldest first — the invoice itself keeps only the latest text. The
// audit line reads "Query raised on {reference}: {text}", so the prefix comes back off.
function queriesRaised(invoice) {
    const prefix = `Query raised on ${invoice.reference_no}: `;
    const texts = (invoice.query_logs ?? [])
        .map((log) => String(log?.description ?? ''))
        .map((text) => (text.startsWith(prefix) ? text.slice(prefix.length) : text).trim())
        .filter((text) => text !== '');
    return texts.length ? texts.join(', ') : '—';
}

// Every payment request this invoice was on that got rejected, oldest first. Rejection returns the
// invoice to Finance and the next request takes its place, so these come from the rejection history
// rather than from `payment_request` — mirrors InvoiceReport::rejectedPaymentRequests.
function rejectedPaymentRequests(invoice) {
    const entries = (invoice.rejections ?? [])
        .map((rejection) => {
            const reference = String(rejection?.payment_request?.reference_no ?? '');
            return reference ? `${reference} (rejected ${shortDate(rejection.rejected_at)})` : '';
        })
        .filter((entry) => entry !== '');
    return entries.length ? entries.join(', ') : '—';
}

// One request's remarks in the order they were written: approvers' comments by stage, then the
// reason it was rejected or withdrawn, labelled so they read as what they are.
function requestRemarks(pr) {
    const remarks = [...(pr?.approvals ?? [])]
        .sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0))
        .map((approval) => String(approval?.comments ?? '').trim());

    const rejection = String(pr?.rejection_reason ?? '').trim();
    if (rejection) remarks.push(`Rejected: ${rejection}`);

    const withdrawal = String(pr?.withdrawal_reason ?? '').trim();
    if (withdrawal) remarks.push(`Withdrawn: ${withdrawal}`);

    return remarks.filter((text) => text !== '');
}

// Everything written on the invoice's payment requests — the rejected ones first, each remark
// carrying its reference so a twice-rejected invoice stays readable, then the request it is on now.
// Mirrors InvoiceReport::approverRemarks.
function approverRemarks(invoice) {
    const written = (invoice.rejections ?? [])
        .flatMap((rejection) => {
            const pr = rejection?.payment_request;
            return pr ? requestRemarks(pr).map((remark) => `[${pr.reference_no}] ${remark}`) : [];
        })
        .concat(requestRemarks(invoice.payment_request));

    return written.length ? written.join(', ') : '—';
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

// A tile lists this many currencies (the server sends the busiest first); the rest sit in a tooltip.
const VISIBLE_CURRENCIES = 3;

const nonZero = (amounts) => (amounts ?? []).filter((a) => a && Number(a.amount) !== 0);

const summaryCards = computed(() => [
    {
        status: 'all',
        label: 'Matching requests',
        count: totals.value.count,
        amounts: nonZero(totals.value.amounts),
        color: 'rgb(var(--v-theme-primary))',
    },
    ...summary.value.map((s) => ({
        status: s.status,
        label: statusLabel(s.status),
        count: s.count,
        amounts: nonZero(s.amounts),
        color: STATUS_META[s.status]?.color ?? '#898781',
    })),
]);

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

        <!-- One total per currency — never a sum across currencies. -->
        <div class="summary-grid mb-4">
            <v-card v-for="card in summaryCards" :key="card.status" class="summary-tile pa-4" :style="{ borderLeftColor: card.color }">
                <div class="d-flex align-center text-body-medium text-medium-emphasis summary-label">
                    <span class="summary-dot mr-2" :style="{ backgroundColor: card.color }" />
                    {{ card.label }}
                </div>
                <div class="text-headline-small font-weight-bold tabular mt-1">{{ Number(card.count ?? 0).toLocaleString() }}</div>

                <v-divider class="my-2" />

                <div v-if="!card.amounts.length" class="text-body-medium text-medium-emphasis tabular">{{ money(0) }}</div>
                <div v-for="a in card.amounts.slice(0, VISIBLE_CURRENCIES)" :key="a.currency" class="summary-amount text-body-medium">
                    <span class="text-medium-emphasis">{{ a.currency }}</span>
                    <span class="tabular">{{ money(a.amount, '') }}</span>
                </div>
                <v-tooltip v-if="card.amounts.length > VISIBLE_CURRENCIES" location="bottom">
                    <template #activator="{ props }">
                        <div v-bind="props" class="text-body-small text-primary mt-1 summary-more">
                            +{{ card.amounts.length - VISIBLE_CURRENCIES }} more
                        </div>
                    </template>
                    <div v-for="a in card.amounts.slice(VISIBLE_CURRENCIES)" :key="a.currency" class="summary-amount text-body-medium">
                        <span>{{ a.currency }}</span>
                        <span class="tabular">{{ money(a.amount, '') }}</span>
                    </div>
                </v-tooltip>
            </v-card>
        </div>

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
                <template #item.rejected_payment_requests="{ item }">
                    <div class="text-body-2" style="min-width: 160px; white-space: pre-wrap">{{ rejectedPaymentRequests(item) }}</div>
                </template>
                <template #item.query_raised="{ item }">
                    <div class="text-body-2" style="min-width: 180px; white-space: pre-wrap">{{ queriesRaised(item) }}</div>
                </template>
                <template #item.remarks="{ item }">
                    <div class="text-body-2" style="min-width: 180px; white-space: pre-wrap">{{ approverRemarks(item) }}</div>
                </template>
            </v-data-table-server>
        </v-card>
    </div>
</template>

<style scoped>
.tabular {
    font-variant-numeric: tabular-nums;
}

/* Every tile on one row on desktop, wrapping evenly on narrower screens. */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 16px;
}

.summary-tile {
    border-left: 4px solid transparent;
}

.summary-label {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.summary-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.summary-amount {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    line-height: 1.6;
}

.summary-more {
    cursor: default;
    width: fit-content;
}
</style>
