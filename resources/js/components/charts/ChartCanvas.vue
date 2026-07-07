<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    ArcElement, BarController, BarElement, CategoryScale, Chart, DoughnutController,
    Legend, LinearScale, LineController, LineElement, PointElement, Tooltip,
} from 'chart.js';

Chart.register(
    ArcElement, BarController, BarElement, CategoryScale, DoughnutController,
    Legend, LinearScale, LineController, LineElement, PointElement, Tooltip,
);

// recessive chart chrome shared by every chart
Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", sans-serif';
Chart.defaults.color = '#898781';
Chart.defaults.borderColor = '#e1e0d9';

const props = defineProps({
    type: { type: String, required: true },
    data: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
    height: { type: Number, default: 260 },
});

const canvas = ref(null);
let chart = null;

onMounted(() => {
    chart = new Chart(canvas.value, {
        type: props.type,
        data: props.data,
        options: { responsive: true, maintainAspectRatio: false, ...props.options },
    });
});

watch(
    () => props.data,
    (data) => {
        if (!chart) return;
        chart.data = data;
        chart.update();
    },
    { deep: true }
);

onBeforeUnmount(() => chart?.destroy());
</script>

<template>
    <div :style="{ height: `${height}px`, position: 'relative' }">
        <canvas ref="canvas" />
    </div>
</template>
