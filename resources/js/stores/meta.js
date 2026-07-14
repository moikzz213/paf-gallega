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
        approvers: [],
        upload: { max_documents: 10, max_document_kb: 10240, mimes: '' },
        loaded: false,
        approversLoaded: false,
    }),

    actions: {
        async load(force = false) {
            if (this.loaded && !force) return;
            const { data } = await api.get('/meta');
            Object.assign(this, data, { loaded: true });
        },

        async loadApprovers(force = false) {
            if (this.approversLoaded && !force) return;
            const { data } = await api.get('/approvers');
            this.approvers = data;
            this.approversLoaded = true;
        },
    },
});
