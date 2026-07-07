<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import api from '../services/api';
import { dateTime } from '../utils/format';

const loading = ref(false);
const items = ref([]);
const total = ref(0);
const actions = ref([]);
const options = reactive({ page: 1, itemsPerPage: 25 });

const filters = reactive({
    q: '',
    action: null,
    date_from: null,
    date_to: null,
});

const headers = [
    { title: 'When', key: 'created_at', sortable: false },
    { title: 'User', key: 'user', sortable: false },
    { title: 'Action', key: 'action', sortable: false },
    { title: 'Description', key: 'description', sortable: false },
    { title: 'Request', key: 'invoice', sortable: false },
    { title: 'IP', key: 'ip_address', sortable: false },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/audit-logs', {
            params: {
                page: options.page,
                per_page: options.itemsPerPage,
                q: filters.q || undefined,
                action: filters.action || undefined,
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

onMounted(async () => {
    const { data } = await api.get('/audit-logs/actions');
    actions.value = data;
});
</script>

<template>
    <div>
        <div class="mb-6">
            <h1 class="text-h5 font-weight-bold">Audit Trail</h1>
            <div class="text-body-2 text-medium-emphasis">Every action across the platform, newest first</div>
        </div>

        <v-card class="mb-4">
            <v-card-text>
                <v-row dense>
                    <v-col cols="12" md="4">
                        <v-text-field v-model="filters.q" label="Search description or reference" prepend-inner-icon="mdi-magnify" clearable hide-details />
                    </v-col>
                    <v-col cols="12" sm="6" md="3">
                        <v-select v-model="filters.action" :items="actions" label="Action" clearable hide-details />
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-text-field v-model="filters.date_from" label="From" type="date" hide-details />
                    </v-col>
                    <v-col cols="6" md="2">
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
                <template #item.created_at="{ item }">
                    <span style="white-space: nowrap">{{ dateTime(item.created_at) }}</span>
                </template>
                <template #item.user="{ item }">
                    {{ item.user?.name ?? 'System' }}
                </template>
                <template #item.action="{ item }">
                    <v-chip size="small" variant="tonal">{{ item.action }}</v-chip>
                </template>
                <template #item.invoice="{ item }">
                    <router-link
                        v-if="item.invoice"
                        :to="`/invoices/${item.invoice.id}`"
                        class="text-primary text-decoration-none"
                    >
                        {{ item.invoice.reference_no }}
                    </router-link>
                    <span v-else>—</span>
                </template>
            </v-data-table-server>
        </v-card>
    </div>
</template>
