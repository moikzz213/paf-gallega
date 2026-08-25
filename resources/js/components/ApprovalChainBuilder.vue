<script setup>
import { computed, ref, watch } from 'vue';
import { money } from '../utils/format';
import { useAuthStore } from '../stores/auth';
import { useMetaStore } from '../stores/meta';

const props = defineProps({
    total: { type: Number, default: 0 },
    currency: { type: String, default: undefined },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['change']);

const auth = useAuthStore();
const meta = useMetaStore();

// Finance both raises requests and can be nominated as an approver, so the person building this
// chain may appear in the approver list. Nobody approves their own request — the server refuses it
// (PaymentRequestService::create), so it is never offered here either.
const candidates = computed(() => (meta.approvers ?? []).filter((a) => a.id !== auth.user?.id));

// A level's min_amount is an amount in the base currency, so a request raised in another currency
// has to be converted before it is measured against one — otherwise USD 36,000 is compared with a
// 50,000 threshold as if it were AED 36,000 and misses the level that should sign it. Mirrors
// ApprovalLevel::requiredForInvoices on the server, which is what actually decides the chain.
const baseCurrency = computed(() => meta.base_currency || 'AED');

// null when the total cannot be converted — an unrated currency, or a mixed selection that has no
// single currency to convert from. Never falls back to 1:1: that is the mis-routing being fixed.
const baseTotal = computed(() => {
    const total = Number(props.total || 0);
    const code = props.currency;

    if (!code || code === baseCurrency.value) return total;
    if (code === 'MULTI-CURRENCY') return null;

    const rate = Number(meta.exchange_rates?.[code]);

    return rate > 0 ? Math.round(total * rate * 100) / 100 : null;
});

const converted = computed(() => baseTotal.value !== null && props.currency && props.currency !== baseCurrency.value);

// Levels the current total must pass through, in order.
const requiredLevels = computed(() =>
    baseTotal.value === null
        ? []
        : [...(meta.approval_levels ?? [])]
              .filter((l) => l.is_active && Number(l.min_amount) <= baseTotal.value)
              .sort((a, b) => a.level - b.level)
);

const assignments = ref({}); // { [level]: approverId }
const adhoc = ref([]); // [{ approver_id, label }]

const ROLE_LABELS = { admin: 'Admin', finance: 'Finance', approver: 'Approver' };

function approverOption(a) {
    // Finance approvers are labelled Finance, not Approver — whoever builds the chain should see
    // which hat the person is wearing. The job title comes first because that is the job description
    // recorded against the stage and printed on the PAF (see PaymentRequestController::buildStages).
    const role = ROLE_LABELS[a.role] ?? a.role;
    const hat = a.role === 'admin' ? role : `${role} · L${a.approval_level ?? '—'}`;

    return {
        value: a.id,
        title: a.name,
        job_title: a.job_title || null,
        subtitle: [a.job_title, hat, a.department].filter(Boolean).join(' · '),
    };
}

const approverItems = computed(() => candidates.value.map(approverOption));

// Each level only offers users configured for that level. The level's own default approver is
// always kept in the list so a pre-filled value can never point at an option that isn't there.
const itemsByLevel = computed(() => {
    const map = {};
    requiredLevels.value.forEach((l) => {
        map[l.level] = candidates.value
            .filter((a) => Number(a.approval_level) === Number(l.level) || a.id === l.default_approver_id)
            .map(approverOption);
    });
    return map;
});

const levelsWithoutApprovers = computed(() =>
    requiredLevels.value.filter((l) => !(itemsByLevel.value[l.level] ?? []).length)
);

// Render each option with its subtitle (avoids fragile item.raw access in a custom slot).
function approverItemProps(item) {
    return { title: item.title, subtitle: item.subtitle };
}

const allAssigned = computed(
    () => baseTotal.value !== null && requiredLevels.value.every((l) => !!assignments.value[l.level])
);

// Pre-fill each required level with its default approver (without clobbering user choices).
// Also keyed on itemsByLevel so the pre-fill re-runs once the approver list finishes loading.
watch(
    [requiredLevels, itemsByLevel],
    ([levels]) => {
        const next = {};
        levels.forEach((l) => {
            const chosen = assignments.value[l.level] ?? l.default_approver_id ?? null;
            const eligible = (itemsByLevel.value[l.level] ?? []).some((i) => i.value === chosen);
            next[l.level] = eligible ? chosen : null;
        });
        assignments.value = next;
    },
    { immediate: true, deep: true }
);

watch(
    [assignments, adhoc],
    () => {
        emit('change', {
            assignments: { ...assignments.value },
            adhoc: adhoc.value.filter((s) => s.approver_id).map((s) => ({ ...s })),
            valid: allAssigned.value,
        });
    },
    { deep: true, immediate: true }
);

function addAdhoc() {
    adhoc.value.push({ approver_id: null, label: '' });
}

function removeAdhoc(index) {
    adhoc.value.splice(index, 1);
}

defineExpose({ reset: () => { assignments.value = {}; adhoc.value = []; } });
</script>

<template>
    <div>
        <v-alert
            v-if="baseTotal === null"
            type="warning"
            variant="tonal"
            density="comfortable"
            :text="
                currency === 'MULTI-CURRENCY'
                    ? `The selected invoices are in different currencies, so there is no single amount to measure against the approval thresholds (which are ${baseCurrency} amounts). Narrow the selection to one currency.`
                    : `No exchange rate is configured for ${currency}. Approval thresholds are ${baseCurrency} amounts, so this request cannot be routed until an administrator sets the ${currency} rate under Master Data → Currencies.`
            "
        />

        <v-alert
            v-else-if="!requiredLevels.length"
            type="warning"
            variant="tonal"
            density="comfortable"
            text="No active approval levels apply to this amount. Ask an administrator to configure approval levels."
        />

        <template v-else>
            <div class="text-caption text-medium-emphasis mb-3">
                This request will route through {{ requiredLevels.length }} level(s)
                (total {{ money(total, currency) }}<template v-if="converted">, worth
                {{ money(baseTotal, baseCurrency) }} at the configured rate — the thresholds are
                {{ baseCurrency }} amounts</template>). Each level only lists users assigned to that level;
                approvers are pre-filled from the level's default — change them as needed.
                Each stage is recorded and printed under the approver's own <strong>job title</strong>,
                falling back to the level name if they have none.
                You are not listed: nobody approves a request they raise themselves.
            </div>

            <v-alert
                v-if="levelsWithoutApprovers.length"
                type="warning"
                variant="tonal"
                density="compact"
                class="mb-3"
                :text="`No selectable user is assigned to level(s) ${levelsWithoutApprovers.map((l) => l.level).join(', ')}. Ask an administrator to set that approval level on an approver, or on a Finance user who should sign off at that level.`"
            />

            <div v-for="lvl in requiredLevels" :key="lvl.id ?? lvl.level" class="d-flex align-center ga-3 mb-2">
                <v-chip size="small" color="primary" variant="tonal" class="flex-shrink-0" style="min-width: 44px; justify-content: center">
                    L{{ lvl.level }}
                </v-chip>
                <div class="text-body-2 font-weight-medium flex-shrink-0" style="width: 150px">{{ lvl.name }}</div>
                <v-select
                    v-model="assignments[lvl.level]"
                    :items="itemsByLevel[lvl.level] ?? []"
                    item-title="title"
                    item-value="value"
                    :item-props="approverItemProps"
                    label="Approver"
                    :no-data-text="`No user is assigned to level ${lvl.level}`"
                    density="compact"
                    hide-details
                    clearable
                    :disabled="disabled"
                />
            </div>

            <v-divider class="my-4" />

            <div class="d-flex align-center mb-2">
                <div class="text-body-2 font-weight-medium">Additional approvers</div>
                <span class="text-caption text-medium-emphasis ml-2">(optional, added after the levels above)</span>
                <v-spacer />
                <v-btn size="small" variant="tonal" prepend-icon="mdi-plus" :disabled="disabled" @click="addAdhoc">
                    Add approver
                </v-btn>
            </div>

            <div v-for="(stage, i) in adhoc" :key="i" class="d-flex align-center ga-3 mb-2">
                <v-chip size="small" variant="tonal" class="flex-shrink-0" style="min-width: 44px; justify-content: center">
                    +{{ i + 1 }}
                </v-chip>
                <v-text-field
                    v-model="stage.label"
                    label="Role / label"
                    placeholder="e.g. Legal review"
                    density="compact"
                    hide-details
                    style="width: 150px"
                    class="flex-shrink-0"
                    :disabled="disabled"
                />
                <v-select
                    v-model="stage.approver_id"
                    :items="approverItems"
                    item-title="title"
                    item-value="value"
                    :item-props="approverItemProps"
                    label="Approver"
                    density="compact"
                    hide-details
                    :disabled="disabled"
                />
                <v-btn icon="mdi-close" variant="text" size="small" :disabled="disabled" @click="removeAdhoc(i)" />
            </div>
        </template>
    </div>
</template>
