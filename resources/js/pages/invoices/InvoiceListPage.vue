<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import api from '../../services/api';
import { money, shortDate, STATUS_META } from '../../utils/format';
import { useMetaStore } from '../../stores/meta';
import StatusChip from '../../components/StatusChip.vue';

const meta = useMetaStore();
const loading = ref(false);
const items = ref([]);
const total = ref(0);

const filters = reactive({
    q: '',
    status: [],
    department: null,
    date_from: null,
    date_to: null,
});

const options = reactive({ page: 1, itemsPerPage: 15 });

const headers = [
    { title: 'Reference', key: 'reference_no' },
    { title: 'Vendor', key: 'vendor_name' },
    { title: 'Invoice #', key: 'invoice_no', sortable: false },
    { title: 'Department', key: 'department', sortable: false },
    { title: 'Requested By', key: 'submitter', sortable: false },
    { title: 'Total', key: 'total_amount', align: 'end' },
    { title: 'Status', key: 'status' },
    { title: 'Invoice Date', key: 'invoice_date' },
];

const statusOptions = Object.entries(STATUS_META).map(([value, m]) => ({ value, title: m.label }));

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/invoices', {
            params: {
                page: options.page,
                per_page: options.itemsPerPage,
                q: filters.q || undefined,
                status: filters.status.length ? filters.status.join(',') : undefined,
                department: filters.department || undefined,
                date_from: filters.date_from || undefined,
                date_to: filters.date_to || undefined,
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
</script>

<template>
    <div>
        <div class="d-flex align-center mb-6">
            <div>
                <h1 class="text-h5 font-weight-bold">Payment Requests</h1>
                <div class="text-body-2 text-medium-emphasis">Submit and track invoice payment requests</div>
            </div>
            <v-spacer />
            <v-btn color="primary" prepend-icon="mdi-plus" to="/invoices/new">New Request</v-btn>
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
                            :items="statusOptions"
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
                <template #item.submitter="{ item }">
                    {{ item.submitter?.name }}
                </template>
                <template #item.total_amount="{ item }">
                    <span style="font-variant-numeric: tabular-nums">{{ money(item.total_amount, item.currency) }}</span>
                </template>
                <template #item.status="{ item }">
                    <StatusChip :status="item.status" />
                </template>
                <template #item.invoice_date="{ item }">
                    {{ shortDate(item.invoice_date) }}
                </template>
            </v-data-table-server>
        </v-card>
    </div>
</template>
