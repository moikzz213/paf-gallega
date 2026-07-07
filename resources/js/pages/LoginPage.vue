<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { errorMessage } from '../services/api';

const auth = useAuthStore();
const router = useRouter();

const email = ref('');
const password = ref('');
const showPassword = ref(false);
const loading = ref(false);
const error = ref('');

async function submit() {
    error.value = '';
    loading.value = true;
    try {
        await auth.login({ email: email.value, password: password.value });
        router.push({ name: 'dashboard' });
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <v-main class="bg-background d-flex align-center justify-center" style="min-height: 100vh">
        <v-card class="pa-4" width="420" elevation="4">
            <v-card-item class="text-center">
                <v-avatar color="primary" size="56" class="mb-3">
                    <v-icon color="white" size="30">mdi-file-sign</v-icon>
                </v-avatar>
                <v-card-title class="text-h5 font-weight-bold">PAF</v-card-title>
                <v-card-subtitle>Invoice Payment Approval Platform</v-card-subtitle>
            </v-card-item>
            <v-card-text>
                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
                    {{ error }}
                </v-alert>
                <v-form @submit.prevent="submit">
                    <v-text-field
                        v-model="email"
                        label="Email"
                        type="email"
                        prepend-inner-icon="mdi-email-outline"
                        autocomplete="username"
                        required
                    />
                    <v-text-field
                        v-model="password"
                        label="Password"
                        :type="showPassword ? 'text' : 'password'"
                        prepend-inner-icon="mdi-lock-outline"
                        :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
                        autocomplete="current-password"
                        required
                        @click:append-inner="showPassword = !showPassword"
                    />
                    <v-btn type="submit" color="primary" size="large" block :loading="loading" class="mt-2">
                        Sign in
                    </v-btn>
                </v-form>
            </v-card-text>
        </v-card>
    </v-main>
</template>
