<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api, { errorMessage } from '../services/api';

const route = useRoute();
const router = useRouter();

const token = String(route.query.token ?? '');
const email = ref(String(route.query.email ?? ''));
const password = ref('');
const passwordConfirmation = ref('');
const showPassword = ref(false);
const loading = ref(false);
const error = ref('');
const done = ref('');

// A link that lost its query string cannot be completed; say so rather than
// letting the user fill the form and fail on submit.
const linkIsUsable = computed(() => token !== '' && email.value !== '');

async function submit() {
    error.value = '';
    loading.value = true;
    try {
        const { data } = await api.post('/reset-password', {
            token,
            email: email.value,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
        });
        done.value = data.message;
        setTimeout(() => router.push({ name: 'login' }), 1500);
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
                <v-card-title class="text-h5 font-weight-bold">Choose a new password</v-card-title>
                <v-card-subtitle v-if="email">{{ email }}</v-card-subtitle>
            </v-card-item>
            <v-card-text>
                <v-alert v-if="done" type="success" variant="tonal" density="compact" class="mb-4">
                    {{ done }}
                </v-alert>
                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
                    {{ error }}
                </v-alert>
                <v-alert v-if="!linkIsUsable" type="warning" variant="tonal" density="compact" class="mb-4">
                    This reset link is incomplete. Please request a new one.
                </v-alert>

                <v-form v-if="linkIsUsable && !done" @submit.prevent="submit">
                    <v-text-field
                        v-model="password"
                        label="New password"
                        :type="showPassword ? 'text' : 'password'"
                        prepend-inner-icon="mdi-lock-outline"
                        :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
                        autocomplete="new-password"
                        hint="At least 8 characters"
                        persistent-hint
                        required
                        @click:append-inner="showPassword = !showPassword"
                    />
                    <v-text-field
                        v-model="passwordConfirmation"
                        label="Confirm new password"
                        :type="showPassword ? 'text' : 'password'"
                        prepend-inner-icon="mdi-lock-check-outline"
                        autocomplete="new-password"
                        class="mt-2"
                        required
                    />
                    <v-btn type="submit" color="primary" size="large" block :loading="loading" class="mt-4">
                        Reset password
                    </v-btn>
                </v-form>

                <div class="text-center mt-4">
                    <router-link :to="{ name: 'forgot-password' }" class="text-caption text-primary">
                        Request a new link
                    </router-link>
                </div>
            </v-card-text>
        </v-card>
    </v-main>
</template>
