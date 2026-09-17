<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import api, { errorMessage } from '../../services/api';
import { money } from '../../utils/format';
import { useMetaStore } from '../../stores/meta';
import { useNotifyStore } from '../../stores/notify';

const meta = useMetaStore();
const notify = useNotifyStore();

const loading = ref(true);
const saving = ref(false);
const levels = ref([]);

const dialog = ref(false);
const editing = ref(null);
const form = ref({});
const formRef = ref(null);

const rules = {
    required: (v) => (v !== null && v !== undefined && v !== '') || 'Required',
    nonNegative: (v) => Number(v) >= 0 || 'Cannot be negative',
};

const ROLE_LABELS = { admin: 'Admin', finance: 'Finance', approver: 'Approver' };

/**
 * The default is pre-filled onto every chain at this level, so only users assigned to **this** level
 * can be chosen — the same membership rule the chain builder enforces. The level's currently saved
 * approver stays in the list even if their own level has since moved, so editing the name or
 * threshold can never silently clear it; it is flagged when that happens.
 * Mirrors ApprovalLevelController::belongsToLevel.
 */
const approverItems = computed(() => {
    const level = Number(form.value.level);
    const keepId = editing.value?.default_approver_id ?? null;

    return (meta.approvers ?? [])
        .filter((a) => Number(a.approval_level) === level || a.id === keepId)
        .map((a) => {
            const role = ROLE_LABELS[a.role] ?? a.role;
            const parts = [
                a.job_title,
                a.role === 'admin' ? role : `${role} · L${a.approval_level ?? '—'}`,
                a.department,
            ];

            if (Number(a.approval_level) !== level) {
                parts.push(`⚠ not assigned to L${level}`);
            }

            return { value: a.id, title: a.name, subtitle: parts.filter(Boolean).join(' · ') };
        });
});

function approverItemProps(item) {
    return { title: item.title, subtitle: item.subtitle };
}

// Retyping the level number re-scopes the list, so drop a selection that no longer belongs to it
// rather than leaving a stale id in the form for the server to reject.
watch(() => form.value.level, () => {
    const selected = form.value.default_approver_id;
    if (selected && !approverItems.value.some((item) => item.value === selected)) {
        form.value.default_approver_id = null;
    }
});

async function load() {
    loading.value = true;
    try {
        const [{ data }] = await Promise.all([api.get('/approval-levels'), meta.loadApprovers()]);
        levels.value = data;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function openCreate() {
    editing.value = null;
    form.value = { level: (levels.value.at(-1)?.level ?? 0) + 1, name: '', min_amount: 0, default_approver_id: null, is_active: true };
    dialog.value = true;
}

function openEdit(level) {
    editing.value = level;
    form.value = { level: level.level, name: level.name, min_amount: level.min_amount, default_approver_id: level.default_approver_id ?? null, is_active: level.is_active };
    dialog.value = true;
}

async function save() {
    const { valid } = await formRef.value.validate();
    if (!valid) return;

    saving.value = true;
    try {
        if (editing.value) {
            await api.put(`/approval-levels/${editing.value.id}`, form.value);
            notify.success('Approval level updated.');
        } else {
            await api.post('/approval-levels', form.value);
            notify.success('Approval level created.');
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

async function remove(level) {
    if (!confirm(`Delete level ${level.level} (${level.name})? Existing approval history keeps its records.`)) return;
    try {
        await api.delete(`/approval-levels/${level.id}`);
        notify.success('Approval level deleted.');
        await load();
        await meta.load(true);
    } catch (e) {
        notify.error(errorMessage(e));
    }
}
</script>

<template>
    <div style="max-width: 900px">
        <div class="d-flex align-center mb-6">
            <div>
                <h1 class="text-h5 font-weight-bold">Approval Levels</h1>
                <div class="text-body-2 text-medium-emphasis">
                    A request passes through every active level whose threshold its total meets, in order
                </div>
            </div>
            <v-spacer />
            <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">New Level</v-btn>
        </div>

        <v-card>
            <v-data-table
                :headers="[
                    { title: 'Level', key: 'level' },
                    { title: 'Name', key: 'name', sortable: false },
                    { title: 'Applies from (total ≥)', key: 'min_amount' },
                    { title: 'Default approver', key: 'default_approver', sortable: false },
                    { title: 'Status', key: 'is_active', sortable: false },
                    { title: '', key: 'actions', align: 'end', sortable: false },
                ]"
                :items="levels"
                :loading="loading"
                density="comfortable"
                hide-default-footer
                :items-per-page="-1"
            >
                <template #item.level="{ item }">
                    <v-chip size="small" color="primary" variant="tonal">L{{ item.level }}</v-chip>
                </template>
                <template #item.min_amount="{ item }">
                    {{ Number(item.min_amount) === 0 ? 'All requests' : money(item.min_amount) }}
                </template>
                <template #item.default_approver="{ item }">
                    <span v-if="item.default_approver">{{ item.default_approver.name }}</span>
                    <span v-else class="text-medium-emphasis">— none —</span>
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
            </v-data-table>
        </v-card>

        <v-dialog v-model="dialog" max-width="480">
            <v-card>
                <v-card-title>{{ editing ? 'Edit Approval Level' : 'New Approval Level' }}</v-card-title>
                <v-card-text>
                    <v-form ref="formRef" @submit.prevent>
                        <v-text-field v-model.number="form.level" label="Level *" type="number" min="1" max="10" :rules="[rules.required]" />
                        <v-text-field v-model="form.name" label="Name * (e.g. Finance Director)" :rules="[rules.required]" />
                        <v-text-field
                            v-model.number="form.min_amount"
                            label="Applies when total ≥ *"
                            type="number" min="0" step="0.01"
                            :rules="[rules.required, rules.nonNegative]"
                            hint="0 means this level reviews every request"
                            persistent-hint
                        />
                        <v-select
                            v-model="form.default_approver_id"
                            :items="approverItems"
                            item-title="title"
                            item-value="value"
                            :item-props="approverItemProps"
                            label="Default approver"
                            clearable
                            :no-data-text="`No user is assigned to level ${form.level || '—'}. Set this approval level on a user first.`"
                            :hint="`Only users assigned to L${form.level || '—'} are listed. Pre-filled onto each request's chain for this level (Finance can change it).`"
                            persistent-hint
                            class="mt-1"
                        />
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
    </div>
</template>
