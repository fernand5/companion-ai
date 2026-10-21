<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, onMounted } from 'vue'

import ErrorNotice from '@/components/ErrorNotice.vue'
import ProgressionCard from '@/components/ProgressionCard.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useProgressionStore } from '@/stores/progression'

const store = useProgressionStore()

onMounted(() => {
  store.load()
})

/** Recommendations you can act on first; kept and informational ones follow. */
const ordered = computed(() =>
  [...store.items].sort((a, b) => Number(b.actionable) - Number(a.actionable)),
)
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

      <ProgressionCard
        v-for="item in ordered"
        :key="item.exercise.name"
        :item="item"
        :state="store.stateFor(item.exercise.name)"
        @accept="store.accept(item)"
        @keep="store.keep(item)"
        @refresh="store.load()"
      />
    </template>
  </section>
</template>
