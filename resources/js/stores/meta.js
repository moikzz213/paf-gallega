import { defineStore } from 'pinia';
import api from '../services/api';

export const useMetaStore = defineStore('meta', {
    state: () => ({
        business_units: [],
        departments: [],
        locations: [],
        currencies: [],
        payment_methods: {},
        priorities: [],
        statuses: [],
        payment_statuses: [],
        pr_statuses: [],
        roles: [],
        approval_levels: [],
        approvers: [],
        vendors: [],
        customers: [],
        upload: { max_documents: 10, max_document_kb: 10240, mimes: '' },
        loaded: false,
        approversLoaded: false,
        vendorsLoaded: false,
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

        async loadVendors(force = false) {
            if (this.vendorsLoaded && !force) return;
            const { data } = await api.get('/vendors');
            this.vendors = data;
            this.vendorsLoaded = true;
        },
    },
});
