import { defineStore } from 'pinia';
import api from '../services/api';

export const useMetaStore = defineStore('meta', {
    state: () => ({
        categories: [],
        departments: [],
        currencies: [],
        payment_methods: {},
        priorities: [],
        statuses: [],
        roles: [],
        approval_levels: [],
        upload: { max_documents: 10, max_document_kb: 10240, mimes: '' },
        loaded: false,
    }),

    actions: {
        async load(force = false) {
            if (this.loaded && !force) return;
            const { data } = await api.get('/meta');
            Object.assign(this, data, { loaded: true });
        },
    },
});
