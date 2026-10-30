<script setup lang="ts">
import type { WeeklyPlanDay } from '@/types'
import { DAY_STATE, stripLabels } from '@/utils/weekDay'

defineProps<{
  days: WeeklyPlanDay[]
  selected: string | null
  /** The id of the panel that shows the selected day, when there is one. */
  controls?: string
}>()

const emit = defineEmits<{ select: [date: string] }>()

function describe(day: WeeklyPlanDay): string {
  const long = new Date(`${day.date}T00:00:00`).toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' })

  const steps = day.steps?.length ? ', steps logged' : ''

  return `${long}${day.is_today ? ', today' : ''}: ${DAY_STATE[day.state].label}${steps}`
}
</script>

<template>
  <nav aria-label="Days of this week">
    <ul class="grid grid-cols-7 gap-1" data-testid="week-strip">
      <li v-for="day in days" :key="day.date" class="min-w-0">
        <button
          type="button"
          class="flex min-h-14 w-full flex-col items-center justify-center gap-0.5 rounded-lg border px-0.5 py-1 text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
          :class="
            selected === day.date
              ? 'border-brand-600 bg-brand-50 text-brand-700'
              : day.is_today
                ? 'border-brand-300 bg-white'
                : 'border-slate-200 bg-white hover:border-slate-300'
          "
          :aria-label="describe(day)"
          :aria-pressed="selected === day.date"
          :aria-controls="controls"
          :aria-current="day.is_today ? 'date' : undefined"
          :data-date="day.date"
          :data-state="day.state"
          @click="emit('select', day.date)"
        >
          <span class="text-[11px] leading-none font-medium uppercase">{{ stripLabels(day.date).weekday }}</span>
          <span class="text-sm leading-none font-semibold" :class="day.is_today ? 'text-brand-700' : 'text-slate-900'">
            {{ stripLabels(day.date).day }}
          </span>
          <span class="flex items-center gap-0.5" aria-hidden="true">
            <span class="h-1.5 w-1.5 rounded-full" :class="DAY_STATE[day.state].dot" />
            <span v-if="day.steps?.length" class="h-1.5 w-1.5 rounded-full bg-teal-400" data-testid="steps-dot" />
          </span>
        </button>
      </li>
    </ul>
  </nav>
</template>
