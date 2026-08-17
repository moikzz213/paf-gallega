<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import api, { errorMessage } from '../services/api';
import { invoicesCurrency, invoicesVendor, money, shortDate } from '../utils/format';
import { useAuthStore } from '../stores/auth';
import { useMetaStore } from '../stores/meta';
import { useNotifyStore } from '../stores/notify';
import StatusChip from '../components/StatusChip.vue';
import ApprovalChainBuilder from '../components/ApprovalChainBuilder.vue';

const auth = useAuthStore();
const meta = useMetaStore();
const notify = useNotifyStore();

const loading = ref(false);
const items = ref([]);
const total = ref(0);
const search = ref('');
const filters = reactive({ department: null, vendor: null });
const options = reactive({ page: 1, itemsPerPage: 15 });

const headers = [
    { title: 'Reference', key: 'reference_no', sortable: false },
    { title: 'Vendor', key: 'vendor_name', sortable: false },
    { title: 'Invoices', key: 'invoices', sortable: false },
    { title: 'Total', key: 'total_amount', align: 'end', sortable: false },
    { title: 'Stage', key: 'stage', sortable: false },
    { title: 'Status', key: 'status', sortable: false },
    { title: 'Created', key: 'created_at', sortable: false },
    { title: '', key: 'actions', sortable: false, width: '40px' },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/payment-requests', {
            params: {
                page: options.page,
                per_page: options.itemsPerPage,
                q: search.value || undefined,
                department: filters.department || undefined,
                vendor: filters.vendor || undefined,
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
    meta.load();
    meta.loadApprovers();
});

let debounce = null;
watch([search, () => filters.department, () => filters.vendor], () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        options.page = 1;
        load();
    }, 350);
});

// ---- create flow ----
const emptyEligibleFilters = () => ({ department: null, currency: null, vendor: null, invoice_no: '', job_no: '', customer: null });
const create = reactive({ show: false, loadingEligible: false, eligible: [], selected: [], saving: false, filters: emptyEligibleFilters() });
const chain = ref({ assignments: {}, adhoc: [], valid: false });

const selectedInvoices = computed(() => create.eligible.filter((i) => create.selected.includes(i.id)));
const selectedTotal = computed(() => selectedInvoices.value.reduce((s, i) => s + Number(i.total_amount), 0));

// One request, one currency — the total is a plain sum and the approval thresholds run off it.
const selectedCurrency = computed(() => invoicesCurrency(selectedInvoices.value));
const mixedCurrency = computed(() => selectedCurrency.value === 'MULTI-CURRENCY');

async function loadEligible() {
    create.loadingEligible = true;
    try {
        const f = create.filters;
        const { data } = await api.get('/payment-requests/eligible', {
            params: {
                per_page: 200,
                department: f.department || undefined,
                currency: f.currency || undefined,
                vendor: f.vendor || undefined,
                invoice_no: f.invoice_no || undefined,
                job_no: f.job_no || undefined,
                customer: f.customer || undefined,
            },
        });
        create.eligible = data.data;
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        create.loadingEligible = false;
    }
}

function openCreate() {
    create.selected = [];
    create.filters = emptyEligibleFilters();
    create.show = true;
    loadEligible();
}

let eligDebounce = null;
watch(() => create.filters, () => {
    if (!create.show) return;
    clearTimeout(eligDebounce);
    eligDebounce = setTimeout(loadEligible, 350);
}, { deep: true });

function onChainChange(payload) {
    chain.value = payload;
}

async function submitCreate() {
    if (!create.selected.length) return notify.error('Select at least one invoice.');
    if (mixedCurrency.value) return notify.error('All selected invoices must share one currency.');
    if (!chain.value.valid) return notify.error('Assign an approver for every approval stage.');

    const approvers = {};
    Object.entries(chain.value.assignments).forEach(([lvl, id]) => {
        if (id) approvers[lvl] = id;
    });
    const adhoc_approvers = chain.value.adhoc
        .filter((s) => s.approver_id)
        .map((s) => ({ approver_id: s.approver_id, label: s.label || null }));

    create.saving = true;
    try {
        const { data } = await api.post('/payment-requests', {
            invoice_ids: create.selected,
            approvers,
            adhoc_approvers,
        });
        notify.success(`${data.reference_no} created and sent for approval.`);
        create.show = false;
        await load();
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        create.saving = false;
    }
}
</script>

<template>
    <div>
        <div class="d-flex align-center mb-6">
            <div>
                <h1 class="text-h5 font-weight-bold">Payment Requests</h1>
                <div class="text-body-2 text-medium-emphasis">Group posted invoices into a payment request and route it for approval</div>
            </div>
            <v-spacer />
            <v-btn v-if="auth.canProcessPayments" color="primary" prepend-icon="mdi-plus" @click="openCreate">New Payment Request</v-btn>
        </div>

        <v-card class="mb-4">
            <v-card-text>
                <v-row dense>
                    <v-col cols="12" md="4">
                        <v-text-field
                            v-model="search"
                            label="Search reference, invoice"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                            hide-details="auto"
                            autocomplete="off"
                        />
                    </v-col>
                    <v-col cols="12" sm="6" md="3">
                        <v-autocomplete
                            v-model="filters.vendor"
                            :items="(meta.vendors ?? []).map(v => v.name)"
                            label="Vendor"
                            clearable
                            hide-details="auto"
                            autocomplete="off"
                        />
                    </v-col>
                    <v-col cols="12" sm="6" md="3">
                        <v-autocomplete
                            v-model="filters.department"
                            :items="meta.departments"
                            label="Department"
                            clearable
                            hide-details="auto"
                            autocomplete="off"
                        />
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
                no-data-text="No payment requests yet."
                @update:options="load"
            >
                <template #item.reference_no="{ item }">
                    <router-link :to="`/payment-requests/${item.id}`" class="text-primary text-decoration-none font-weight-medium">
                        {{ item.reference_no }}
                    </router-link>
                    <div class="text-caption text-medium-emphasis">by {{ item.creator?.name }}</div>
                </template>
                <template #item.vendor_name="{ item }">
                    <span :title="invoicesVendor(item.invoices).all">{{ invoicesVendor(item.invoices).label }}</span>
                </template>
                <template #item.invoices="{ item }">
                    {{ item.invoices?.length ?? 0 }} invoice(s)
                </template>
                <template #item.total_amount="{ item }">
                    <span style="font-variant-numeric: tabular-nums" class="font-weight-medium">
                        {{ money(item.total_amount, invoicesCurrency(item.invoices)) }}
                    </span>
                </template>
                <template #item.stage="{ item }">
                    <template v-if="item.status === 'in_approval'">
                        {{ item.current_stage }} / {{ item.approvals?.length }}
                        <div v-if="item.approvals?.find(a => a.sequence === item.current_stage)?.approver?.name" class="text-caption text-medium-emphasis">
                            {{ item.approvals.find(a => a.sequence === item.current_stage).approver.name }}
                        </div>
                    </template>
                    <span v-else class="text-medium-emphasis">—</span>
                </template>
                <template #item.status="{ item }">
                    <StatusChip :status="item.status" size="small" />
                </template>
                <template #item.created_at="{ item }">
                    {{ shortDate(item.created_at) }}
                </template>
                <template #item.actions="{ item }">
                    <v-btn v-if="item.status === 'approved' || item.status === 'paid'" icon="mdi-file-pdf-box" size="small" variant="text" :href="`/api/payment-requests/${item.id}/pdf`" target="_blank" title="Download PDF" />
                </template>
            </v-data-table-server>
        </v-card>

        <!-- Create dialog -->
        <v-dialog v-model="create.show" max-width="100%" scrollable>
            <v-card>
                <v-card-title>New Payment Request</v-card-title>
                <v-card-subtitle>Select posted invoices, then build the approval chain</v-card-subtitle>
                <v-divider />
                <v-card-text style="max-height: 90vh">
                    <div v-if="create.loadingEligible" class="d-flex justify-center py-8">
                        <v-progress-circular indeterminate color="primary" />
                    </div>
                    <template v-else>
                        <v-row dense class="mb-1">
                            <v-col cols="12" sm="6" md="2">
                                <v-autocomplete v-model="create.filters.department" :items="meta.departments" label="Department" clearable hide-details="auto" autocomplete="off" density="compact" />
                            </v-col>
                            <v-col cols="12" sm="6" md="2">
                                <v-autocomplete v-model="create.filters.vendor" :items="(meta.vendors ?? []).map(v => v.name)" label="Vendor name" clearable hide-details="auto" autocomplete="off" density="compact" />
                            </v-col>
                            <v-col cols="12" sm="6" md="2">
                                <v-text-field v-model="create.filters.invoice_no" label="Invoice No" clearable hide-details="auto" autocomplete="off" density="compact" />
                            </v-col>
                            <v-col cols="12" sm="6" md="2">
                                <v-text-field v-model="create.filters.job_no" label="Job No" clearable hide-details="auto" autocomplete="off" density="compact" />
                            </v-col>
                            <v-col cols="12" sm="6" md="2">
                                <v-autocomplete v-model="create.filters.customer" :items="(meta.customers ?? []).map(c => c.name)" label="Customer Name" clearable hide-details="auto" autocomplete="off" density="compact" />
                            </v-col>
                            <v-col cols="12" sm="6" md="2">
                                <v-autocomplete v-model="create.filters.currency" :items="meta.currencies" label="Currency" clearable hide-details="auto" autocomplete="off" density="compact" />
                            </v-col>
                        </v-row>
                        <div class="text-subtitle-2 mb-2">Eligible invoices ({{ create.eligible.length }})</div>
                        <v-data-table
                            v-model="create.selected"
                            :headers="[
                                { title: 'Reference', key: 'reference_no', sortable: false },
                                { title: 'Vendor / Invoice #', key: 'vendor_name', sortable: false },
                                { title: 'Job / Customer', key: 'jobs', sortable: false },
                                { title: 'Dept', key: 'department', sortable: false },
                                { title: 'Total', key: 'total_amount', align: 'end', sortable: false },
                            ]"
                            :items="create.eligible"
                            item-value="id"
                            show-select
                            density="compact"
                            hide-default-footer
                            :items-per-page="-1"
                            no-data-text="No posted invoices match these filters."
                        >
                            <template #item.vendor_name="{ item }">
                                {{ item.vendor_name }}
                                <div class="text-caption text-medium-emphasis">{{ item.invoice_no }}</div>
                            </template>
                            <template #item.jobs="{ item }">
                                <div v-for="(it, idx) in (item.items || [])" :key="idx" class="text-caption">
                                    {{ it.job_no || '—' }}<span v-if="it.customer"> · {{ it.customer.name }}</span>
                                </div>
                                <span v-if="!(item.items || []).length" class="text-caption text-medium-emphasis">—</span>
                            </template>
                            <template #item.total_amount="{ item }">
                                {{ money(item.total_amount, item.currency) }}
                            </template>
                        </v-data-table>

                        <div class="d-flex align-center my-4">
                            <v-chip color="primary" variant="tonal">{{ create.selected.length }} selected</v-chip>
                            <v-spacer />
                            <div class="text-body-1">
                                <span class="text-medium-emphasis">Total:</span>
                                <strong>{{ money(selectedTotal, selectedCurrency) }}</strong>
                            </div>
                        </div>

                        <v-alert
                            v-if="mixedCurrency"
                            type="warning"
                            variant="tonal"
                            density="compact"
                            class="mb-4"
                            text="The selected invoices are in different currencies. A payment request covers one currency only — narrow the selection before sending."
                        />

                        <v-divider class="mb-4" />
                        <div class="text-subtitle-2 mb-2">Approval chain</div>
                        <ApprovalChainBuilder :total="selectedTotal" :currency="selectedCurrency" @change="onChainChange" />
                    </template>
                </v-card-text>
                <v-divider />
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="create.show = false">Cancel</v-btn>
                    <v-btn color="primary" variant="flat" :loading="create.saving" prepend-icon="mdi-send" @click="submitCreate">
                        Send for Approval
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
