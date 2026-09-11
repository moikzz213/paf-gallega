<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import api from '../services/api';
import { money, shortDate, STATUS_META, statusLabel } from '../utils/format';
import { useAuthStore } from '../stores/auth';
import StatusChip from '../components/StatusChip.vue';
import ChartCanvas from '../components/charts/ChartCanvas.vue';

const auth = useAuthStore();
const loading = ref(true);
const refreshing = ref(false);
const dash = ref(null);

// Invoice-log statuses, in a stable donut order
const DONUT_ORDER = ['posted', 'submitted', 'query_raised', 'cancelled'];

// --- Period filter -------------------------------------------------------------------------
// Every card and chart below reports on this one window, so nothing on the screen is silently
// using a different one. The server resolves and bounds whatever is sent, and echoes back what it
// actually used in `period`; that echo is what labels the figures.

const now = new Date();
const ym = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
const monthsBack = (n) => ym(new Date(now.getFullYear(), now.getMonth() - n, 1));

const yearToDate = () => ({ from: `${now.getFullYear()}-01`, to: ym(now), allTime: false });

const PRESETS = [
    { label: 'This Month', range: () => ({ from: ym(now), to: ym(now), allTime: false }) },
    { label: 'Last 3 Months', range: () => ({ from: monthsBack(2), to: ym(now), allTime: false }) },
    { label: 'Last 6 Months', range: () => ({ from: monthsBack(5), to: ym(now), allTime: false }) },
    { label: 'Year to Date', range: yearToDate },
    { label: 'Last 12 Months', range: () => ({ from: monthsBack(11), to: ym(now), allTime: false }) },
    { label: 'All Time', range: () => ({ from: null, to: null, allTime: true }) },
];

const period = reactive(yearToDate());

function applyPreset(preset) {
    Object.assign(period, preset.range());
}

/** Which preset chip reads as selected — purely cosmetic, a custom range matches none of them. */
const activePreset = computed(() => PRESETS.find((preset) => {
    const range = preset.range();
    return range.allTime === period.allTime && range.from === period.from && range.to === period.to;
})?.label);

// The window the server actually used, which is not always the one asked for: it swaps an inverted
// range and caps an excessive one.
const periodLabel = computed(() => dash.value?.period?.label ?? '');

// A month input reports every keystroke on some browsers, so a half-typed "2026-0" would reach the
// server and come back 422. Only whole months are worth a request; an empty box is fine, the server
// resolves the missing bound.
const MONTH = /^\d{4}-(0[1-9]|1[0-2])$/;
const usable = (value) => !value || MONTH.test(value);

async function load() {
    if (!period.allTime && !(usable(period.from) && usable(period.to))) return;

    refreshing.value = true;
    try {
        const { data } = await api.get('/dashboard', {
            params: period.allTime
                ? { all: 1 }
                : { from: period.from || undefined, to: period.to || undefined },
        });
        dash.value = data;
    } finally {
        refreshing.value = false;
        loading.value = false;
    }
}

onMounted(load);
watch(period, load);

const cards = computed(() => {
    if (!dash.value) return [];
    const c = dash.value.cards;
    const within = periodLabel.value;
    return [
        { title: 'Total Invoices', value: c.total_invoices, caption: `submitted · ${within}`, icon: 'mdi-file-document-multiple-outline', color: '#256abf' },
        { title: 'Awaiting Posting', value: c.awaiting_posting.count, caption: `${money(c.awaiting_posting.amount)} · ${within}`, icon: 'mdi-timer-sand', color: '#2a78d6' },
        { title: 'In Approval', value: c.in_approval.count, caption: `${money(c.in_approval.amount)} · ${within}`, icon: 'mdi-clock-outline', color: '#eda100' },
        { title: 'Paid', value: c.paid.count, caption: `${money(c.paid.amount)} · ${within}`, icon: 'mdi-check-circle-outline', color: '#008300' },
    ];
});

const donutData = computed(() => {
    const rows = dash.value?.status_distribution ?? [];
    const byStatus = Object.fromEntries(rows.map((r) => [r.status, r]));
    const present = DONUT_ORDER.filter((s) => byStatus[s]);
    return {
        labels: present.map((s) => `${statusLabel(s)} (${byStatus[s].count})`),
        datasets: [{
            data: present.map((s) => byStatus[s].count),
            backgroundColor: present.map((s) => STATUS_META[s].color),
            borderColor: '#fcfcfb', // 2px surface gap between segments
            borderWidth: 2,
        }],
    };
});

const monthlyData = computed(() => ({
    labels: (dash.value?.monthly ?? []).map((m) => m.month),
    datasets: [
        {
            label: 'Submitted',
            data: (dash.value?.monthly ?? []).map((m) => m.submitted),
            backgroundColor: '#2a78d6',
            borderRadius: { topLeft: 4, topRight: 4 },
            maxBarThickness: 22,
        },
        {
            label: 'Paid',
            data: (dash.value?.monthly ?? []).map((m) => m.paid),
            backgroundColor: '#008300',
            borderRadius: { topLeft: 4, topRight: 4 },
            maxBarThickness: 22,
        },
    ],
}));

const vendorData = computed(() => ({
    labels: (dash.value?.top_vendors ?? []).map((v) => v.vendor_name),
    datasets: [{
        data: (dash.value?.top_vendors ?? []).map((v) => Number(v.amount)),
        backgroundColor: '#256abf', // single series, sequential blue
        borderRadius: { topRight: 4, bottomRight: 4 },
        maxBarThickness: 18,
    }],
}));

const businessUnitData = computed(() => ({
    labels: (dash.value?.by_business_unit ?? []).map((c) => c.business_unit),
    datasets: [{
        data: (dash.value?.by_business_unit ?? []).map((c) => Number(c.amount)),
        backgroundColor: '#199e70', // second sequential context: aqua, darkened to clear 3:1
        borderRadius: { topRight: 4, bottomRight: 4 },
        maxBarThickness: 18,
    }],
}));

const moneyTooltip = {
    callbacks: {
        label: (ctx) => ` ${ctx.dataset.label ? ctx.dataset.label + ': ' : ''}${money(ctx.parsed.x ?? ctx.parsed.y ?? ctx.parsed)}`,
    },
};

const hbarOptions = {
    indexAxis: 'y',
    plugins: { legend: { display: false }, tooltip: moneyTooltip },
    scales: {
        x: { grid: { color: '#e1e0d9' }, ticks: { callback: (v) => (v >= 1000 ? `${v / 1000}k` : v) } },
        y: { grid: { display: false } },
    },
};

const recentHeaders = [
    { title: 'Reference', key: 'reference_no' },
    { title: 'Vendor', key: 'vendor_name' },
    { title: 'Amount', key: 'total_amount', align: 'end' },
    { title: 'Status', key: 'status' },
    { title: 'Date', key: 'created_at' },
];
</script>

<template>
    <div>
        <div class="d-flex align-center mb-6">
            <div>
                <h1 class="text-h5 font-weight-bold">Dashboard</h1>
                <div class="text-body-2 text-medium-emphasis">Welcome back, {{ auth.user?.name }}</div>
            </div>
            <v-spacer />
            <v-btn color="primary" prepend-icon="mdi-plus" to="/invoices/new">New Request</v-btn>
        </div>

        <v-card class="mb-6">
            <v-card-text>
                <div class="d-flex flex-wrap align-center ga-2 mb-3">
                    <v-chip
                        v-for="preset in PRESETS"
                        :key="preset.label"
                        :variant="activePreset === preset.label ? 'flat' : 'outlined'"
                        :color="activePreset === preset.label ? 'primary' : undefined"
                        size="small"
                        @click="applyPreset(preset)"
                    >
                        {{ preset.label }}
                    </v-chip>
                    <v-spacer />
                    <v-progress-circular v-if="refreshing && !loading" indeterminate color="primary" size="20" width="2" />
                </div>
                <v-row dense align="center">
                    <v-col cols="6" sm="3" md="2">
                        <v-text-field
                            v-model="period.from"
                            label="From"
                            type="month"
                            density="compact"
                            hide-details
                            :disabled="period.allTime"
                        />
                    </v-col>
                    <v-col cols="6" sm="3" md="2">
                        <v-text-field
                            v-model="period.to"
                            label="To"
                            type="month"
                            density="compact"
                            hide-details
                            :disabled="period.allTime"
                        />
                    </v-col>
                    <v-col cols="12" sm="6" md="8">
                        <div class="text-caption text-medium-emphasis">
                            Showing <strong>{{ periodLabel }}</strong>. Each figure uses its own date —
                            invoices by when they were submitted, Paid by when payment was made, and the spend
                            charts by invoice date — so the cards overlap rather than describe one identical set.
                            The approval banner is a live queue and is never filtered by this period.
                        </div>
                    </v-col>
                </v-row>
            </v-card-text>
        </v-card>

        <div v-if="loading" class="d-flex justify-center py-16">
            <v-progress-circular indeterminate color="primary" size="48" />
        </div>

        <template v-else>
            <v-alert
                v-if="auth.canApprove && dash.my_queue > 0"
                type="warning"
                variant="tonal"
                class="mb-6"
                icon="mdi-stamper"
            >
                <strong>{{ dash.my_queue }}</strong> request{{ dash.my_queue > 1 ? 's are' : ' is' }} waiting for your approval.
                <template #append>
                    <v-btn color="warning" variant="flat" size="small" to="/approvals">Review now</v-btn>
                </template>
            </v-alert>

            <v-row>
                <v-col v-for="card in cards" :key="card.title" cols="12" sm="6" lg="3">
                    <v-card class="pa-1">
                        <v-card-text class="d-flex align-center">
                            <v-avatar :style="{ backgroundColor: card.color + '1a' }" size="48" class="mr-4">
                                <v-icon :style="{ color: card.color }">{{ card.icon }}</v-icon>
                            </v-avatar>
                            <div>
                                <div class="text-h4 font-weight-bold">{{ card.value }}</div>
                                <div class="text-body-2 text-medium-emphasis">{{ card.title }}</div>
                                <div class="text-caption text-medium-emphasis">{{ card.caption }}</div>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-row>
                <v-col cols="12" md="5">
                    <v-card>
                        <v-card-title class="text-subtitle-1 d-flex align-center">
                            Requests by Status
                            <v-spacer />
                            <span class="text-caption text-medium-emphasis font-weight-regular">{{ periodLabel }}</span>
                        </v-card-title>
                        <v-card-text>
                            <ChartCanvas
                                type="doughnut"
                                :data="donutData"
                                :options="{
                                    cutout: '62%',
                                    plugins: { legend: { position: 'right', labels: { boxWidth: 12, boxHeight: 12, usePointStyle: true } } },
                                }"
                                :height="280"
                            />
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" md="7">
                    <v-card>
                        <v-card-title class="text-subtitle-1 d-flex align-center">
                            Monthly Flow — Submitted vs Paid (AED)
                            <v-spacer />
                            <span class="text-caption text-medium-emphasis font-weight-regular">{{ periodLabel }}</span>
                        </v-card-title>
                        <v-card-text>
                            <ChartCanvas
                                type="bar"
                                :data="monthlyData"
                                :options="{
                                    plugins: { legend: { position: 'top', align: 'end', labels: { boxWidth: 12, boxHeight: 12, usePointStyle: true } }, tooltip: moneyTooltip },
                                    scales: {
                                        x: { grid: { display: false } },
                                        y: { grid: { color: '#e1e0d9' }, ticks: { callback: (v) => (v >= 1000 ? `${v / 1000}k` : v) } },
                                    },
                                }"
                                :height="280"
                            />
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-row>
                <v-col cols="12" md="6">
                    <v-card>
                        <v-card-title class="text-subtitle-1 d-flex align-center">
                            Top Vendors by Spend
                            <v-spacer />
                            <span class="text-caption text-medium-emphasis font-weight-regular">by invoice date · {{ periodLabel }}</span>
                        </v-card-title>
                        <v-card-text>
                            <ChartCanvas type="bar" :data="vendorData" :options="hbarOptions" :height="240" />
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" md="6">
                    <v-card>
                        <v-card-title class="text-subtitle-1 d-flex align-center">
                            Spend by Business Unit
                            <v-spacer />
                            <span class="text-caption text-medium-emphasis font-weight-regular">by invoice date · {{ periodLabel }}</span>
                        </v-card-title>
                        <v-card-text>
                            <ChartCanvas type="bar" :data="businessUnitData" :options="hbarOptions" :height="240" />
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-card class="mt-2">
                <v-card-title class="text-subtitle-1 d-flex align-center">
                    Recent Requests
                    <v-spacer />
                    <v-btn variant="text" size="small" color="primary" to="/invoices">View all</v-btn>
                </v-card-title>
                <v-data-table
                    :headers="recentHeaders"
                    :items="dash.recent"
                    density="comfortable"
                    hide-default-footer
                    :items-per-page="-1"
                >
                    <template #item.reference_no="{ item }">
                        <router-link :to="`/invoices/${item.id}`" class="text-primary text-decoration-none font-weight-medium">
                            {{ item.reference_no }}
                        </router-link>
                    </template>
                    <template #item.total_amount="{ item }">
                        <span class="tabular">{{ money(item.total_amount, item.currency) }}</span>
                    </template>
                    <template #item.status="{ item }">
                        <StatusChip :status="item.status" />
                    </template>
                    <template #item.created_at="{ item }">
                        {{ shortDate(item.created_at) }}
                    </template>
                </v-data-table>
            </v-card>
        </template>
    </div>
</template>

<style scoped>
.tabular {
    font-variant-numeric: tabular-nums;
}
</style>
