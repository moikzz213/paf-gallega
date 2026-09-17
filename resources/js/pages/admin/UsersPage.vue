<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import api, { errorMessage } from '../../services/api';
import { useMetaStore } from '../../stores/meta';
import { useNotifyStore } from '../../stores/notify';

const meta = useMetaStore();
const notify = useNotifyStore();

const loading = ref(false);
const saving = ref(false);
const items = ref([]);
const total = ref(0);
const options = reactive({ page: 1, itemsPerPage: 15 });
const filters = reactive({ q: '', role: null });

const dialog = ref(false);
const editing = ref(null);
const form = ref({});
const formRef = ref(null);

const rules = {
    required: (v) => (v !== null && v !== undefined && v !== '') || 'Required',
    email: (v) => /.+@.+\..+/.test(v) || 'Invalid email',
};

const roleLabels = { admin: 'Administrator', requester: 'Requester', approver: 'Approver', finance: 'Finance' };
// Approvers must have a level; Finance may be given one to become selectable as an approver.
const takesLevel = (role) => role === 'approver' || role === 'finance';
const roleColors = { admin: '#4a3aa7', requester: '#2a78d6', approver: '#eda100', finance: '#1baf7a' };

const headers = [
    { title: 'Name', key: 'name', sortable: false },
    { title: 'Email', key: 'email', sortable: false },
    { title: 'Role', key: 'role', sortable: false },
    { title: 'Level', key: 'approval_level', sortable: false },
    { title: 'Department', key: 'department', sortable: false },
    { title: 'Status', key: 'is_active', sortable: false },
    { title: '', key: 'actions', align: 'end', sortable: false },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/users', {
            params: {
                page: options.page,
                per_page: options.itemsPerPage,
                q: filters.q || undefined,
                role: filters.role || undefined,
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

function openCreate() {
    editing.value = null;
    form.value = { name: '', email: '', password: '', role: 'requester', approval_level: null, department: null, job_title: '', is_active: true };
    dialog.value = true;
}

function openEdit(user) {
    editing.value = user;
    form.value = { ...user, password: '' };
    dialog.value = true;
}

async function save() {
    const { valid } = await formRef.value.validate();
    if (!valid) return;

    saving.value = true;
    try {
        const payload = { ...form.value };
        if (!takesLevel(payload.role)) payload.approval_level = null;
        if (!payload.password) delete payload.password;

        if (editing.value) {
            await api.put(`/users/${editing.value.id}`, payload);
            notify.success('User updated.');
        } else {
            await api.post('/users', payload);
            notify.success('User created.');
        }
        dialog.value = false;
        await load();
    } catch (e) {
        notify.error(errorMessage(e));
    } finally {
        saving.value = false;
    }
}

onMounted(() => meta.load());
</script>

<template>
    <div>
        <div class="d-flex align-center mb-6">
            <div>
                <h1 class="text-h5 font-weight-bold">Users</h1>
                <div class="text-body-2 text-medium-emphasis">Manage accounts, roles and approval levels</div>
            </div>
            <v-spacer />
            <v-btn color="primary" prepend-icon="mdi-account-plus" @click="openCreate">New User</v-btn>
        </div>

        <v-card class="mb-4">
            <v-card-text>
                <v-row dense>
                    <v-col cols="12" md="4">
                        <v-text-field v-model="filters.q" label="Search name or email" prepend-inner-icon="mdi-magnify" clearable hide-details />
                    </v-col>
                    <v-col cols="12" sm="6" md="3">
                        <v-select
                            v-model="filters.role"
                            :items="Object.entries(roleLabels).map(([value, title]) => ({ value, title }))"
                            label="Role" clearable hide-details
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
                @update:options="load"
            >
                <template #item.role="{ item }">
                    <v-chip size="small" variant="tonal" :style="{ color: roleColors[item.role] }">
                        {{ roleLabels[item.role] ?? item.role }}
                    </v-chip>
                </template>
                <template #item.approval_level="{ item }">
                    {{ item.approval_level != null ? `L${item.approval_level}` : '—' }}
                </template>
                <template #item.is_active="{ item }">
                    <v-chip size="small" variant="tonal" :style="{ color: item.is_active ? '#008300' : '#d03b3b' }">
                        {{ item.is_active ? 'Active' : 'Inactive' }}
                    </v-chip>
                </template>
                <template #item.actions="{ item }">
                    <v-btn icon="mdi-pencil-outline" variant="text" size="small" @click="openEdit(item)" />
                </template>
            </v-data-table-server>
        </v-card>

        <v-dialog v-model="dialog" max-width="560">
            <v-card>
                <v-card-title>{{ editing ? 'Edit User' : 'New User' }}</v-card-title>
                <v-card-text>
                    <v-form ref="formRef" @submit.prevent>
                        <v-row dense>
                            <v-col cols="12" sm="6">
                                <v-text-field v-model="form.name" label="Name *" :rules="[rules.required]" />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field v-model="form.email" label="Email *" :rules="[rules.required, rules.email]" />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model="form.password"
                                    :label="editing ? 'New password (leave blank to keep)' : 'Password *'"
                                    type="password"
                                    :rules="editing ? [] : [rules.required]"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-select
                                    v-model="form.role"
                                    :items="Object.entries(roleLabels).map(([value, title]) => ({ value, title }))"
                                    label="Role *"
                                />
                            </v-col>
                            <v-col v-if="takesLevel(form.role)" cols="12" sm="6">
                                <v-select
                                    v-model="form.approval_level"
                                    :items="meta.approval_levels.map((l) => ({ value: l.level, title: `L${l.level} — ${l.name}` }))"
                                    :label="form.role === 'approver' ? 'Approval level *' : 'Approval level'"
                                    :hint="form.role === 'finance' ? 'Optional. Set a level to make this Finance user selectable as an approver at that level.' : undefined"
                                    :persistent-hint="form.role === 'finance'"
                                    :rules="form.role === 'approver' ? [rules.required] : []"
                                    :clearable="form.role === 'finance'"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-select v-model="form.department" :items="meta.departments" label="Department" clearable />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field v-model="form.job_title" label="Job title" />
                            </v-col>
                            <v-col cols="12" sm="6" class="d-flex align-center">
                                <v-switch v-model="form.is_active" label="Active" color="success" hide-details />
                            </v-col>
                        </v-row>
                    </v-form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="dialog = false">Cancel</v-btn>
                    <v-btn color="primary" variant="flat" :loading="saving" @click="save">Save</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
