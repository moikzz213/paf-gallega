import { defineStore } from 'pinia';

export const useNotifyStore = defineStore('notify', {
    state: () => ({
        show: false,
        text: '',
        color: 'success',
    }),

    actions: {
        success(text) {
            this.text = text;
            this.color = 'success';
            this.show = true;
        },
        error(text) {
            this.text = text;
            this.color = 'error';
            this.show = true;
        },
    },
});
