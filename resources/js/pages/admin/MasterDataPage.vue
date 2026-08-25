<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import api, { errorMessage } from '../../services/api';
import { money } from '../../utils/format';
import { useMetaStore } from '../../stores/meta';
import { useNotifyStore } from '../../stores/notify';

const meta = useMetaStore();
const notify = useNotifyStore();

const tab = ref('vendors');
const loading = ref(false);
const items = ref([]);
const total = ref(0);
const search = ref('');
const options = reactive({ page: 1, itemsPerPage: 15 });
const saving = ref(false);
const importing = ref(false);
const importDialog = ref(false);
const importFile = ref(null);

const dialog = ref(false);
const editing = ref(null);
const form = ref({});
const formRef = ref(null);

const rules = {
    required: (v) => (v !== null && v !== undefined && v !== '') || 'Required',
    nonNeg: (v) => v === '' || v === null || v === undefined || (!isNaN(Number(v)) && Number(v) >= 0) || 'Must be zero or positive',
    positive: (v) => v === '' || v === null || v === undefined || (!isNaN(Number(v)) && Number(v) > 0) || 'Must be greater than zero',
};

const tabs = [
    { value: 'vendors', label: 'Vendors', icon: 'mdi-office-building-outline' },
    { value: 'customers', label: 'Customers', icon: 'mdi-account-outline' },
    { value: 'business-units', label: 'Business Units', icon: 'mdi-domain' },
    { value: 'departments', label: 'Departments', icon: 'mdi-account-group-outline' },
    { value: 'locations', label: 'Locations', icon: 'mdi-map-marker-outline' },
    { value: 'currencies', label: 'Currencies', icon: 'mdi-currency-usd' },
];

const hasCreditFields = (t) => t === 'vendors' || t === 'customers';

// Approval thresholds are amounts in the base currency, so every other currency needs a rate into
// it before a request raised in that currency can be routed (see ApprovalChainBuilder).
const baseCurrency = computed(() => meta.base_currency || 'AED');
const isBaseCurrency = (name) => String(name ?? '').toUpperCase() === baseCurrency.value;

const tabLabel = (t) => tabs.find((x) => x.value === t)?.label ?? 'master data';
// "Currencies" doesn't singularise by dropping an "s", and a currency's name is its code.
const tabSingular = (t) => (t === 'currencies' ? 'Currency' : tabLabel(t).replace(/s$/, ''));
const nameLabel = (t) => (t === 'currencies' ? 'Currency Code' : 'Name');

function headers(t) {
    const h = [
        { title: nameLabel(t), key: 'name', sortable: false },
        { title: 'Status', key: 'is_active', sortable: false },
        { title: '', key: 'actions', align: 'end', sortable: false },
    ];
    if (t === 'currencies') {
        h.splice(1, 0, { title: `Rate to ${baseCurrency.value}`, key: 'exchange_rate', sortable: false });
    }
    if (hasCreditFields(t)) {
        const codeKey = t === 'vendors' ? 'vendor_code' : 'customer_code';
        h.splice(1, 0, { title: 'Code', key: codeKey, sortable: false });
        h.splice(3, 0, { title: 'Credit Limit', key: 'credit_limit', sortable: false });
        h.splice(4, 0, { title: 'Credit Days', key: 'credit_days', sortable: false });
    }
    return h;
}

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get(`/master-data/${tab.value}`, {
            params: {
                page: options.page,
                per_page: options.itemsPerPage,
                q: search.value || undefined,
            },
        });
        items.value = data.data;
        total.value = data.total;
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    load();
    // The Currencies tab labels its rate column with the base currency, which comes from meta.
    meta.load();
});
watch(tab, () => {
    search.value = '';
    options.page = 1;
    load();
});

let searchTimer;
watch(search, () => {
    clearTimeout(searchTimer);
    options.page = 1;
    searchTimer = setTimeout(load, 300);
});
onBeforeUnmount(() => clearTimeout(searchTimer));

function emptyForm() {
    if (hasCreditFields(tab.value)) {
        return { name: '', vendor_code: '', customer_code: '', credit_limit: null, credit_days: null, is_active: true };
    }
    if (tab.value === 'currencies') {
        return { name: '', exchange_rate: null, is_active: true };
    }
    return { name: '', is_active: true };
}

function formFromItem(item) {
    const base = { name: item.name, is_active: item.is_active };
    if (hasCreditFields(tab.value)) {
        base.vendor_code = item.vendor_code ?? '';
        base.customer_code = item.customer_code ?? '';
        base.credit_limit = item.credit_limit ?? null;
        base.credit_days = item.credit_days ?? null;
    }
    if (tab.value === 'currencies') {
        base.exchange_rate = item.exchange_rate ?? null;
    }
    return base;
}

function openCreate() {
    editing.value = null;
    form.value = emptyForm();
    dialog.value = true;
}

function downloadTemplate() {
    window.open(`/api/master-data/${tab.value}/template`, '_blank');
}

function openImport() {
    importFile.value = null;
    importDialog.value = true;
}

async function importExcel() {
    if (!importFile.value) {
        notify.error('Select an Excel file to import.');
        return;
    }

    importing.value = true;
    try {
        const payload = new FormData();
        payload.append('file', importFile.value);
        const { data } = await api.post(`/master-data/${tab.value}/import`, payload);
        notify.success(data.message);
        importDialog.value = false;
        await load();
        await meta.load(true);
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        importing.value = false;
    }
}

function openEdit(item) {
    editing.value = item;
    form.value = formFromItem(item);
    dialog.value = true;
}

async function save() {
    const { valid } = await formRef.value.validate();
    if (!valid) return;

    saving.value = true;
    try {
        const payload = { ...form.value };
        if (tab.value === 'currencies') {
            // The base currency is 1 by definition — never leave it blank, or nothing in it routes.
            if (isBaseCurrency(payload.name)) payload.exchange_rate = 1;
            else if (payload.exchange_rate === '' || payload.exchange_rate === undefined) payload.exchange_rate = null;
        } else {
            delete payload.exchange_rate;
        }
        if (!hasCreditFields(tab.value)) {
            delete payload.vendor_code;
            delete payload.customer_code;
            delete payload.credit_limit;
            delete payload.credit_days;
        } else {
            if (tab.value === 'vendors') delete payload.customer_code;
            else delete payload.vendor_code;

            if (payload.credit_limit === '' || payload.credit_limit === null || payload.credit_limit === undefined) payload.credit_limit = null;
            if (payload.credit_days === '' || payload.credit_days === null || payload.credit_days === undefined) payload.credit_days = null;
        }

        if (editing.value) {
            await api.put(`/master-data/${tab.value}/${editing.value.id}`, payload);
            notify.success('Updated.');
        } else {
            await api.post(`/master-data/${tab.value}`, payload);
            notify.success('Created.');
        }
        dialog.value = false;
        await load();
        await meta.load(true);
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        saving.value = false;
    }
}

async function remove(item) {
    if (!confirm(`Delete "${item.name}"?`)) return;
    try {
        await api.delete(`/master-data/${tab.value}/${item.id}`);
        notify.success('Deleted.');
        if (items.value.length === 1 && options.page > 1) options.page -= 1;
        await load();
        await meta.load(true);
    } catch (e) {
        notify.error(errorMessage(e));
    }
}
</script>

<template>
    <div style="max-width: 1100px">
        <div class="d-flex flex-wrap align-center ga-2 mb-6">
            <div>
                <h1 class="text-h5 font-weight-bold">Master Data</h1>
                <div class="text-body-2 text-medium-emphasis">Manage vendors, customers, business units, departments, locations and currencies</div>
            </div>
            <v-spacer />
            <v-btn variant="outlined" prepend-icon="mdi-file-download-outline" @click="downloadTemplate">
                Export Template
            </v-btn>
            <v-btn variant="outlined" prepend-icon="mdi-file-upload-outline" @click="openImport">
                Import Excel
            </v-btn>
            <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">New {{ tabSingular(tab) }}</v-btn>
        </div>

        <v-tabs v-model="tab" color="primary" class="mb-4">
            <v-tab v-for="t in tabs" :key="t.value" :value="t.value" :prepend-icon="t.icon">{{ t.label }}</v-tab>
        </v-tabs>

        <v-text-field
            v-model="search"
            :label="`Search ${tabLabel(tab)}${hasCreditFields(tab) ? ' by name or code' : ' by name'}`"
            prepend-inner-icon="mdi-magnify"
            clearable
            hide-details
            class="mb-4"
        />

        <v-card>
            <v-data-table-server
                v-model:page="options.page"
                v-model:items-per-page="options.itemsPerPage"
                :headers="headers(tab)"
                :items="items"
                :items-length="total"
                :loading="loading"
                density="comfortable"
                :items-per-page-options="[10, 15, 25, 50]"
                @update:options="load"
            >
                <template #item.name="{ item }">
                    <span class="font-weight-medium">{{ item.name }}</span>
                </template>
                <template v-if="hasCreditFields(tab)" #item.vendor_code="{ item }">
                    <span class="text-medium-emphasis">{{ item.vendor_code || '—' }}</span>
                </template>
                <template v-if="hasCreditFields(tab)" #item.customer_code="{ item }">
                    <span class="text-medium-emphasis">{{ item.customer_code || '—' }}</span>
                </template>
                <template v-if="tab === 'currencies'" #item.exchange_rate="{ item }">
                    <span v-if="item.exchange_rate != null">1 {{ item.name }} = {{ Number(item.exchange_rate) }} {{ baseCurrency }}</span>
                    <v-chip v-else size="small" color="warning" variant="tonal">Not set — cannot be routed</v-chip>
                </template>

                <template v-if="hasCreditFields(tab)" #item.credit_limit="{ item }">
                    {{ item.credit_limit != null ? money(item.credit_limit) : '—' }}
                </template>
                <template v-if="hasCreditFields(tab)" #item.credit_days="{ item }">
                    {{ item.credit_days != null ? `${item.credit_days} days` : '—' }}
                </template>
                <template #item.is_active="{ item }">
                    <v-chip size="small" variant="tonal" :style="{ color: item.is_active ? '#008300' : '#d03b3b' }">
                        {{ item.is_active ? 'Active' : 'Inactive' }}
                    </v-chip>
                </template>
                <template #item.actions="{ item }">
                    <v-btn icon="mdi-pencil-outline" variant="text" size="small" @click="openEdit(item)" />
                    <v-btn icon="mdi-delete-outline" variant="text" size="small" color="error" @click="remove(item)" />
                </template>
            </v-data-table-server>
        </v-card>

        <v-dialog v-model="dialog" max-width="520">
            <v-card>
                <v-card-title>{{ editing ? 'Edit' : 'New' }} {{ tabSingular(tab) }}</v-card-title>
                <v-card-text>
                    <v-form ref="formRef" @submit.prevent>
                        <v-text-field
                            v-model="form.name"
                            :label="`${nameLabel(tab)} *`"
                            :hint="tab === 'currencies' ? 'Three-letter code in upper case, e.g. AED' : undefined"
                            :persistent-hint="tab === 'currencies'"
                            :rules="[rules.required]"
                            autofocus
                        />
                        <v-text-field
                            v-if="tab === 'currencies'"
                            v-model="form.exchange_rate"
                            :label="`Exchange Rate to ${baseCurrency}`"
                            type="number"
                            min="0"
                            step="0.000001"
                            :disabled="isBaseCurrency(form.name)"
                            :rules="[rules.positive]"
                            :hint="
                                isBaseCurrency(form.name)
                                    ? `${baseCurrency} is the base currency — its rate is always 1.`
                                    : `How much ${baseCurrency} one unit is worth, e.g. 3.6725. Approval thresholds are ${baseCurrency} amounts, so a request in this currency cannot be routed until a rate is set.`
                            "
                            persistent-hint
                            class="mb-2"
                        />
                        <template v-if="hasCreditFields(tab)">
                            <v-text-field v-if="tab === 'vendors'" v-model="form.vendor_code" label="Vendor Code" />
                            <v-text-field v-else v-model="form.customer_code" label="Customer Code" />
                            <v-text-field v-model="form.credit_limit" label="Credit Limit" type="number" min="0" step="0.01" :rules="[rules.nonNeg]" prefix="AED" />
                            <v-text-field v-model="form.credit_days" label="Credit Days" type="number" min="0" max="365" :rules="[rules.nonNeg]" suffix="days" />
                        </template>
                        <v-switch v-model="form.is_active" label="Active" color="success" hide-details class="mt-2" />
                    </v-form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="dialog = false">Cancel</v-btn>
                    <v-btn color="primary" variant="flat" :loading="saving" @click="save">Save</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="importDialog" max-width="560">
            <v-card>
                <v-card-title>Import {{ tabLabel(tab) }}</v-card-title>
                <v-card-text>
                    <v-alert type="info" variant="tonal" class="mb-4">
                        Download the template, keep its column headers unchanged, and upload the completed Excel file.
                        The import is create-only; if any row is invalid or duplicated, no records will be created.
                    </v-alert>
                    <v-file-input
                        v-model="importFile"
                        label="Excel file *"
                        accept=".xlsx,.xls"
                        prepend-icon="mdi-microsoft-excel"
                        show-size
                        clearable
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" :disabled="importing" @click="importDialog = false">Cancel</v-btn>
                    <v-btn color="primary" variant="flat" :loading="importing" :disabled="!importFile" @click="importExcel">
                        Import
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
