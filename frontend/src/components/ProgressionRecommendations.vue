<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, onMounted } from 'vue'

import ErrorNotice from '@/components/ErrorNotice.vue'
import ProgressionCard from '@/components/ProgressionCard.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useProgressionStore } from '@/stores/progression'
import type { ProgressionItem } from '@/types'
import { formatDate } from '@/utils/format'

const store = useProgressionStore()

onMounted(() => {
  store.load()
})

/**
 * Groups cards by the upcoming session each applies to (soonest first); exercises with no upcoming session
 * follow, as information.
 */
function groupBySession(items: ProgressionItem[]) {
  const bySession = new Map<string, { key: string; date: string | null; title: string | null; items: ProgressionItem[] }>()

  for (const item of items) {
    const date = item.applies_to?.date ?? null
    const key = date ?? 'unscheduled'
    const group = bySession.get(key) ?? { key, date, title: item.applies_to?.title ?? null, items: [] }
    group.items.push(item)
    bySession.set(key, group)
  }

  return [...bySession.values()].sort((a, b) => (a.date === null ? 1 : b.date === null ? -1 : a.date.localeCompare(b.date)))
}

/** Something the user can act on: the server issued an approval for it. Everything else is information. */
const canAct = (item: ProgressionItem) => item.actionable && item.proposal !== null

/** A card that is mid-action (working, applied, stale, failed) stays open and in view even if it no longer has an approval. */
const inFlight = (item: ProgressionItem) => store.stateFor(item.exercise.name).status !== 'idle'

const actionable = computed(() => groupBySession(store.items.filter((i) => canAct(i) || inFlight(i))))
const informational = computed(() => groupBySession(store.items.filter((i) => !canAct(i) && !inFlight(i))))
const informationalCount = computed(() => informational.value.reduce((n, g) => n + g.items.length, 0))

const suggested = computed(() => store.summary?.suggested ?? store.items.filter((i) => i.actionable).length)
</script>

<template>
  <section class="space-y-3" aria-labelledby="progression-heading">
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <h2 id="progression-heading" class="font-semibold text-slate-900">Progression</h2>
        <p class="text-xs text-slate-500">
          Calculated from the sets you recorded. Nothing changes until you accept it.
        </p>
      </div>
      <button
        type="button"
        class="flex min-h-11 shrink-0 items-center gap-1.5 rounded-xl px-3 text-sm font-medium text-slate-600 hover:bg-slate-100 disabled:opacity-50"
        :disabled="store.loading"
        data-testid="reload"
        @click="store.load()"
      >
        <FontAwesomeIcon :icon="store.loading ? icons.loading : icons.action.reset" :class="ICON_SIZE.sm" :spin="store.loading" />
        Refresh
      </button>
    </div>

    <p v-if="store.loading && store.items.length === 0" class="flex items-center gap-1.5 text-sm text-slate-400" data-testid="loading">
      <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.sm" spin />
      Looking at your recent sessions…
    </p>

    <div v-else-if="store.error && store.items.length === 0" class="rounded-xl border border-slate-200 bg-white p-4" data-testid="error">
      <ErrorNotice :message="store.error" :on-retry="store.load" />
    </div>

    <p
      v-else-if="store.items.length === 0"
      class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-500"
      data-testid="empty"
    >
      Nothing to show yet. Record the sets you do and progression suggestions will appear here.
    </p>

    <template v-else>
      <p v-if="store.error" class="flex items-center gap-1.5 text-sm text-red-600" data-testid="refresh-error">
        <FontAwesomeIcon :icon="icons.warning" :class="ICON_SIZE.sm" />
        {{ store.error }}
      </p>

      <p class="text-sm text-slate-600" data-testid="summary">
        <template v-if="suggested > 0">
          {{ suggested }} suggested {{ suggested === 1 ? 'adjustment' : 'adjustments' }}. Review each one; accepting one never accepts another.
        </template>
        <template v-else>No adjustments suggested right now. Your plan stays as it is.</template>
      </p>

      <!-- What you can act on: always open. -->
      <div v-for="group in actionable" :key="group.key" class="space-y-2" :data-session="group.key">
        <h3 v-if="group.date" class="text-xs font-medium tracking-wide text-slate-400 uppercase" data-testid="session-heading">
          {{ formatDate(group.date) }}<template v-if="group.title"> · {{ group.title }}</template>
        </h3>
        <h3 v-else class="text-xs font-medium tracking-wide text-slate-400 uppercase" data-testid="session-heading">
          Not in an upcoming session
        </h3>

        <ProgressionCard
          v-for="item in group.items"
          :key="item.exercise.name"
          :item="item"
          :state="store.stateFor(item.exercise.name)"
          @accept="store.accept(item)"
          @keep="store.keep(item)"
          @refresh="store.load()"
        />
      </div>

      <!-- Nothing to approve (not enough evidence, keep as is, kept by you, needs review): collapsed, open to inspect. -->
      <details v-if="informationalCount > 0" class="group rounded-xl border border-slate-200 bg-white" data-testid="no-action">
        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 px-4 py-2 text-sm font-medium text-slate-600 select-none [&::-webkit-details-marker]:hidden">
          <span>{{ informationalCount }} {{ informationalCount === 1 ? 'exercise needs' : 'exercises need' }} no action</span>
          <FontAwesomeIcon :icon="icons.chevronDown" :class="[ICON_SIZE.xs, 'text-slate-400 transition-transform group-open:rotate-180']" />
        </summary>

        <div class="space-y-3 px-3 pb-3">
          <div v-for="group in informational" :key="group.key" class="space-y-2" :data-session="group.key">
            <h3 v-if="group.date" class="text-xs font-medium tracking-wide text-slate-400 uppercase" data-testid="session-heading">
              {{ formatDate(group.date) }}<template v-if="group.title"> · {{ group.title }}</template>
            </h3>
            <h3 v-else class="text-xs font-medium tracking-wide text-slate-400 uppercase" data-testid="session-heading">
              Not in an upcoming session
            </h3>

            <ProgressionCard
              v-for="item in group.items"
              :key="item.exercise.name"
              :item="item"
              :state="store.stateFor(item.exercise.name)"
              collapsible
              @accept="store.accept(item)"
              @keep="store.keep(item)"
              @refresh="store.load()"
            />
          </div>
        </div>
      </details>
    </template>
  </section>
</template>
