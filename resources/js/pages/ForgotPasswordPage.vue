<script setup>
import { ref } from 'vue';
import api, { errorMessage } from '../services/api';

const email = ref('');
const loading = ref(false);
const error = ref('');
const sent = ref('');

async function submit() {
    error.value = '';
    sent.value = '';
    loading.value = true;
    try {
        const { data } = await api.post('/forgot-password', { email: email.value });
        // The API deliberately answers the same way for unknown addresses, so this
        // confirms the request was accepted — not that an account exists.
        sent.value = data.message;
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
                <img :src="'/assets/images/icon.png'" alt="Gallega" style="max-width: 50px; height: auto;" class="mb-2" />
                <v-card-title class="text-h5 font-weight-bold">Forgot password</v-card-title>
                <v-card-subtitle>We'll email you a reset link</v-card-subtitle>
            </v-card-item>
            <v-card-text>
                <v-alert v-if="sent" type="success" variant="tonal" density="compact" class="mb-4">
                    {{ sent }}
                </v-alert>
                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
                    {{ error }}
                </v-alert>

                <v-form v-if="!sent" @submit.prevent="submit">
                    <v-text-field
                        v-model="email"
                        label="Email"
                        type="email"
                        prepend-inner-icon="mdi-email-outline"
                        autocomplete="username"
                        required
                    />
                    <v-btn type="submit" color="primary" size="large" block :loading="loading" class="mt-2">
                        Send reset link
                    </v-btn>
                </v-form>

                <div class="text-center mt-4">
                    <router-link :to="{ name: 'login' }" class="text-caption text-primary">
                        Back to sign in
                    </router-link>
                </div>
            </v-card-text>
        </v-card>
    </v-main>
</template>
