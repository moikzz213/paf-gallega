<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useNotifyStore } from '../stores/notify';
import { roleLabel as formatRoleLabel } from '../utils/format';

const auth = useAuthStore();
const notify = useNotifyStore();
const router = useRouter();
const drawer = ref(true);

const navItems = computed(() => {
    const items = [
        { title: 'Dashboard', icon: 'mdi-view-dashboard-outline', to: '/dashboard' },
        { title: 'Invoice Log', icon: 'mdi-file-document-multiple-outline', to: '/invoices' },
        { title: 'Payment Requests', icon: 'mdi-bank-transfer-out', to: '/payment-requests' },
    ];

    if (auth.canApprove) {
        items.push({ title: 'Approvals', icon: 'mdi-stamper', to: '/approvals' });
    }

    items.push({ title: 'Reports', icon: 'mdi-chart-box-outline', to: '/reports' });

    if (auth.isAdmin) {
        items.push(
            { title: 'Audit Trail', icon: 'mdi-history', to: '/audit-log' },
            { title: 'Users', icon: 'mdi-account-group-outline', to: '/admin/users' },
            { title: 'Approval Levels', icon: 'mdi-format-list-numbered', to: '/admin/approval-levels' },
            { title: 'Master Data', icon: 'mdi-database-outline', to: '/admin/master-data' },
        );
    }

    return items;
});

const roleLabel = computed(() => formatRoleLabel(auth.user));
</script>

<template>
    <v-navigation-drawer v-model="drawer" color="#10243e">
        <div class="pa-4 text-center">
            <img :src="'/assets/images/gallega-logo.jpg'" alt="Gallega" style="max-width: 50px; height: auto;" class="mb-2" />
            <div class="text-caption text-blue-lighten-4">Gallega Vendor Portal</div>
        </div>
        <v-divider color="grey-darken-1" />
        <v-list nav density="comfortable">
            <v-list-item
                v-for="item in navItems"
                :key="item.to"
                :to="item.to"
                :prepend-icon="item.icon"
                :title="item.title"
                color="blue-lighten-3"
                class="text-blue-lighten-5"
            />
        </v-list>
    </v-navigation-drawer>

    <v-app-bar flat border color="surface">
        <v-app-bar-nav-icon @click="drawer = !drawer" />
        <v-toolbar-title class="text-subtitle-1 font-weight-medium">
            Vendor Portal
        </v-toolbar-title>
        <v-spacer />
        <v-menu >
            <template #activator="{ props }" >
                <v-btn v-bind="props" variant="text" class="text-none">
                    <v-avatar color="primary" size="30" class="mr-2">
                        <span class="text-white text-caption">{{ auth.user?.name?.charAt(0) }}</span>
                    </v-avatar>
                    <div class="text-left d-none d-sm-block">
                        <div class="text-body-2">{{ auth.user?.name }}</div>
                        <div class="text-caption text-medium-emphasis">{{ roleLabel }}</div>
                    </div>
                    <v-icon end>mdi-chevron-down</v-icon>
                </v-btn>
            </template>
            <v-list density="compact">
                <v-list-item prepend-icon="mdi-account-circle-outline" title="My Profile" :to="{ name: 'profile' }" />
                <v-divider />
                <v-list-item prepend-icon="mdi-logout" title="Sign out" @click="auth.logout()" />
            </v-list>
        </v-menu>
    </v-app-bar>

    <v-main class="bg-background">
        <v-container fluid class="pa-6">
            <router-view />
        </v-container>
    </v-main>

    <v-snackbar v-model="notify.show" :color="notify.color" location="bottom right" timeout="4000">
        {{ notify.text }}
        <template #actions>
            <v-btn icon="mdi-close" size="small" @click="notify.show = false" />
        </template>
    </v-snackbar>
</template>
