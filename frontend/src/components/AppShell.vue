<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { ICON_SIZE, icons } from '@/constants/icons'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const { t } = useI18n()

// The route names are identifiers; the words come from the message catalog when they are drawn.
const navItems = [
  { name: 'dashboard', label: 'dashboard', icon: icons.nav.dashboard },
  { name: 'weekly-plan', label: 'weeklyPlan', icon: icons.nav.weeklyPlan },
  { name: 'coach', label: 'coach', icon: icons.nav.coach },
  { name: 'activity', label: 'activity', icon: icons.nav.activity },
  { name: 'progress', label: 'progress', icon: icons.nav.progress },
  { name: 'profile', label: 'profile', icon: icons.nav.profile },
] as const

async function handleLogout() {
  await auth.logout()
  // Full navigation so no other store keeps this user's data in memory.
  window.location.assign(router.resolve({ name: 'login' }).href)
}
</script>

<template>
  <div class="flex h-screen flex-col bg-slate-50">
    <header
      class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 sm:px-6"
    >
      <div class="flex items-center gap-2">
        <FontAwesomeIcon :icon="icons.brand" :class="[ICON_SIZE.lg, 'text-brand-600']" />
        <span class="font-semibold text-slate-900">{{ t('nav.brand') }}</span>
      </div>
      <button
        type="button"
        class="text-sm text-slate-500 hover:text-slate-800"
        @click="handleLogout"
      >
        {{ t('nav.logout') }}
      </button>
    </header>

    <div class="flex flex-1 overflow-hidden">
      <nav
        class="hidden w-56 shrink-0 flex-col gap-1 border-r border-slate-200 bg-white p-4 sm:flex"
        :aria-label="t('a11y.primaryNavigation')"
      >
        <RouterLink
          v-for="item in navItems"
          :key="item.name"
          :to="{ name: item.name }"
          class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-brand-50 hover:text-brand-700"
          active-class="bg-brand-50 text-brand-700"
        >
          <FontAwesomeIcon :icon="item.icon" :class="ICON_SIZE.md" fixed-width />
          <span>{{ t(`nav.items.${item.label}`) }}</span>
        </RouterLink>
      </nav>

      <main class="flex-1 overflow-y-auto pb-20 sm:pb-6">
        <slot />
      </main>
    </div>

    <nav
      class="fixed inset-x-0 bottom-0 flex border-t border-slate-200 bg-white sm:hidden"
      :aria-label="t('a11y.primaryNavigation')"
    >
      <RouterLink
        v-for="item in navItems"
        :key="item.name"
        :to="{ name: item.name }"
        class="flex min-w-0 flex-1 flex-col items-center gap-0.5 px-0.5 py-2 text-xs text-slate-500 max-[359px]:text-[11px]"
        active-class="text-brand-600"
      >
        <FontAwesomeIcon :icon="item.icon" :class="ICON_SIZE.md" fixed-width />
        <span class="min-w-0 text-center leading-tight [overflow-wrap:anywhere]">{{ t(`nav.items.${item.label}`) }}</span>
      </RouterLink>
    </nav>
  </div>
</template>
