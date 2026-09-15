<script setup lang="ts">
import {
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  LinearScale,
  LineElement,
  PointElement,
} from 'chart.js'
import { Bar, Line } from 'vue-chartjs'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement)

const props = defineProps<{
  type: 'line' | 'bar'
  labels: string[]
  values: number[]
  label: string
  color?: string
}>()

const options = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: { y: { beginAtZero: true } },
} as const

function chartData() {
  const color = props.color ?? '#16a350'

  return {
    labels: props.labels,
    datasets: [
      {
        label: props.label,
        data: props.values,
        borderColor: color,
        backgroundColor: props.type === 'bar' ? color : `${color}33`,
        tension: 0.3,
        fill: props.type === 'line',
      },
    ],
  }
}
</script>

<template>
  <div class="h-48">
    <Line v-if="type === 'line'" :data="chartData()" :options="options" />
    <Bar v-else :data="chartData()" :options="options" />
  </div>
</template>
