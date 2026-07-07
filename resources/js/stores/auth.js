import { defineStore } from 'pinia';
import api from '../services/api';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        loaded: false,
    }),

    getters: {
        isAdmin: (state) => state.user?.role === 'admin',
        isApprover: (state) => state.user?.role === 'approver',
        isFinance: (state) => state.user?.role === 'finance',
        canApprove: (state) => ['admin', 'approver'].includes(state.user?.role),
        canProcessPayments: (state) => ['admin', 'finance'].includes(state.user?.role),
    },

    actions: {
        async fetchUser() {
            if (this.loaded) return this.user;
            try {
                const { data } = await api.get('/me');
                this.user = data.user;
            } catch {
                this.user = null;
            }
            this.loaded = true;
            return this.user;
        },

        async login(credentials) {
            const { data } = await api.post('/login', credentials);
            this.user = data.user;
            this.loaded = true;
            return this.user;
        },

        async logout() {
            try {
                await api.post('/logout');
            } finally {
                this.user = null;
                window.location.href = '/login';
            }
        },
    },
});
