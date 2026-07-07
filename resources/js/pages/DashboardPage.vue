<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../services/api';
import { money, shortDate, STATUS_META, statusLabel } from '../utils/format';
import { useAuthStore } from '../stores/auth';
import StatusChip from '../components/StatusChip.vue';
import ChartCanvas from '../components/charts/ChartCanvas.vue';

const auth = useAuthStore();
const loading = ref(true);
const dash = ref(null);

// CVD-validated segment order — keep as-is (validated adjacency, not cosmetic)
const DONUT_ORDER = ['paid', 'scheduled', 'pending_approval', 'approved', 'rejected', 'draft', 'cancelled'];

onMounted(async () => {
    const { data } = await api.get('/dashboard');
    dash.value = data;
    loading.value = false;
});

const cards = computed(() => {
    if (!dash.value) return [];
    const c = dash.value.cards;
    return [
        { title: 'Total Requests', value: c.total_requests, caption: 'all time', icon: 'mdi-file-document-multiple-outline', color: '#256abf' },
        { title: 'Pending Approval', value: c.pending.count, caption: money(c.pending.amount), icon: 'mdi-clock-outline', color: '#eda100' },
        { title: 'Awaiting Payment', value: c.awaiting_payment.count, caption: money(c.awaiting_payment.amount), icon: 'mdi-bank-transfer-out', color: '#1baf7a' },
        { title: 'Paid This Month', value: c.paid_this_month.count, caption: money(c.paid_this_month.amount), icon: 'mdi-check-circle-outline', color: '#008300' },
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

const categoryData = computed(() => ({
    labels: (dash.value?.by_category ?? []).map((c) => c.category),
    datasets: [{
        data: (dash.value?.by_category ?? []).map((c) => Number(c.amount)),
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
                        <v-card-title class="text-subtitle-1">Requests by Status</v-card-title>
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
                        <v-card-title class="text-subtitle-1">Monthly Flow — Submitted vs Paid (AED)</v-card-title>
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
                        <v-card-title class="text-subtitle-1">Top Vendors by Spend</v-card-title>
                        <v-card-text>
                            <ChartCanvas type="bar" :data="vendorData" :options="hbarOptions" :height="240" />
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" md="6">
                    <v-card>
                        <v-card-title class="text-subtitle-1">Spend by Category</v-card-title>
                        <v-card-text>
                            <ChartCanvas type="bar" :data="categoryData" :options="hbarOptions" :height="240" />
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
