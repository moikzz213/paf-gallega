<script setup>
import { computed } from 'vue';
import { STATUS_META } from '../utils/format';

const props = defineProps({
    status: { type: String, required: true },
    size: { type: String, default: 'small' },
});

const meta = computed(() => STATUS_META[props.status] ?? { label: props.status, color: '#898781', icon: 'mdi-help-circle-outline' });
</script>

<template>
    <v-chip :size="size" variant="tonal" :prepend-icon="meta.icon" :style="{ color: meta.color }">
        {{ meta.label }}
    </v-chip>
</template>
