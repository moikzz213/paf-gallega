<script setup>
import { computed, ref } from 'vue';
import api, { errorMessage } from '../services/api';
import { useAuthStore } from '../stores/auth';
import { useNotifyStore } from '../stores/notify';
import { dateTime, roleLabel } from '../utils/format';

const auth = useAuthStore();
const notify = useNotifyStore();

const user = computed(() => auth.user);
const initial = computed(() => user.value?.name?.charAt(0)?.toUpperCase() ?? '?');

const form = ref({ current_password: '', password: '', password_confirmation: '' });
const showCurrent = ref(false);
const showNew = ref(false);
const saving = ref(false);
const error = ref('');

// Read-only account facts. Role, department and active state are administered on
// the Users page, so they are shown here rather than made editable.
const details = computed(() => [
    { label: 'Full name', value: user.value?.name, icon: 'mdi-account-outline' },
    { label: 'Email', value: user.value?.email, icon: 'mdi-email-outline' },
    { label: 'Role', value: roleLabel(user.value), icon: 'mdi-shield-account-outline' },
    { label: 'Job title', value: user.value?.job_title, icon: 'mdi-badge-account-outline' },
    { label: 'Department', value: user.value?.department, icon: 'mdi-office-building-outline' },
    { label: 'Member since', value: dateTime(user.value?.created_at), icon: 'mdi-calendar-outline' },
]);

const canSubmit = computed(() =>
    form.value.current_password !== ''
    && form.value.password !== ''
    && form.value.password_confirmation !== ''
);

async function changePassword() {
    error.value = '';
    saving.value = true;
    try {
        const { data } = await api.put('/profile/password', form.value);
        form.value = { current_password: '', password: '', password_confirmation: '' };
        notify.success(data.message);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <h1 class="text-h5 font-weight-medium mb-1">My Profile</h1>
        <p class="text-body-2 text-medium-emphasis mb-6">
            Your account details and password.
        </p>

        <v-row>
            <v-col cols="12" md="5">
                <v-card elevation="1">
                    <v-card-text class="text-center pt-6">
                        <v-avatar color="primary" size="72" class="mb-3">
                            <span class="text-white text-h5">{{ initial }}</span>
                        </v-avatar>
                        <div class="text-h6">{{ user?.name }}</div>
                        <div class="text-body-2 text-medium-emphasis">{{ roleLabel(user) }}</div>
                        <v-chip
                            :color="user?.is_active ? 'success' : 'error'"
                            size="small"
                            variant="tonal"
                            class="mt-3"
                        >
                            {{ user?.is_active ? 'Active' : 'Deactivated' }}
                        </v-chip>
                    </v-card-text>
                    <v-divider />
                    <v-list density="compact" class="py-0">
                        <v-list-item v-for="row in details" :key="row.label" :prepend-icon="row.icon">
                            <v-list-item-title class="text-caption text-medium-emphasis">
                                {{ row.label }}
                            </v-list-item-title>
                            <v-list-item-subtitle class="text-body-2 text-high-emphasis">
                                {{ row.value || '—' }}
                            </v-list-item-subtitle>
                        </v-list-item>
                    </v-list>
                </v-card>
            </v-col>

            <v-col cols="12" md="7">
                <v-card elevation="1">
                    <v-card-item>
                        <v-card-title class="text-subtitle-1 font-weight-medium">Change password</v-card-title>
                        <v-card-subtitle>
                            You'll need your current password to set a new one.
                        </v-card-subtitle>
                    </v-card-item>
                    <v-card-text>
                        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
                            {{ error }}
                        </v-alert>

                        <v-form @submit.prevent="changePassword">
                            <v-text-field
                                v-model="form.current_password"
                                label="Current password"
                                :type="showCurrent ? 'text' : 'password'"
                                prepend-inner-icon="mdi-lock-outline"
                                :append-inner-icon="showCurrent ? 'mdi-eye-off' : 'mdi-eye'"
                                autocomplete="current-password"
                                required
                                @click:append-inner="showCurrent = !showCurrent"
                            />
                            <v-text-field
                                v-model="form.password"
                                label="New password"
                                :type="showNew ? 'text' : 'password'"
                                prepend-inner-icon="mdi-lock-plus-outline"
                                :append-inner-icon="showNew ? 'mdi-eye-off' : 'mdi-eye'"
                                autocomplete="new-password"
                                hint="At least 8 characters, and different from your current one"
                                persistent-hint
                                required
                                @click:append-inner="showNew = !showNew"
                            />
                            <v-text-field
                                v-model="form.password_confirmation"
                                label="Confirm new password"
                                :type="showNew ? 'text' : 'password'"
                                prepend-inner-icon="mdi-lock-check-outline"
                                autocomplete="new-password"
                                class="mt-3"
                                required
                            />
                            <v-btn
                                type="submit"
                                color="primary"
                                :loading="saving"
                                :disabled="!canSubmit"
                                class="mt-4"
                            >
                                Update password
                            </v-btn>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </div>
</template>
