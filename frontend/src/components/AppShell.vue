<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { useRouter } from 'vue-router'

import { ICON_SIZE, icons } from '@/constants/icons'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const navItems = [
  { name: 'dashboard', label: 'Dashboard', icon: icons.nav.dashboard },
  { name: 'coach', label: 'Coach', icon: icons.nav.coach },
  { name: 'activity', label: 'Activity', icon: icons.nav.activity },
  { name: 'progress', label: 'Progress', icon: icons.nav.progress },
  { name: 'profile', label: 'Profile', icon: icons.nav.profile },
]

async function handleLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="flex h-screen flex-col bg-slate-50">
    <header
      class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 sm:px-6"
    >
      <div class="flex items-center gap-2">
        <FontAwesomeIcon :icon="icons.brand" :class="[ICON_SIZE.lg, 'text-brand-600']" />
        <span class="font-semibold text-slate-900">Companion AI Coach</span>
      </div>
      <button
        type="button"
        class="text-sm text-slate-500 hover:text-slate-800"
        @click="handleLogout"
      >
        Log out
      </button>
    </header>

    <div class="flex flex-1 overflow-hidden">
      <nav
        class="hidden w-56 shrink-0 flex-col gap-1 border-r border-slate-200 bg-white p-4 sm:flex"
      >
        <RouterLink
          v-for="item in navItems"
          :key="item.name"
          :to="{ name: item.name }"
          class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-brand-50 hover:text-brand-700"
          active-class="bg-brand-50 text-brand-700"
        >
          <FontAwesomeIcon :icon="item.icon" :class="ICON_SIZE.md" fixed-width />
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>

      <main class="flex-1 overflow-y-auto pb-20 sm:pb-6">
        <slot />
      </main>
    </div>

    <nav
      class="fixed inset-x-0 bottom-0 flex border-t border-slate-200 bg-white sm:hidden"
      aria-label="Primary"
    >
      <RouterLink
        v-for="item in navItems"
        :key="item.name"
        :to="{ name: item.name }"
        class="flex flex-1 flex-col items-center gap-0.5 py-2 text-xs text-slate-500"
        active-class="text-brand-600"
      >
        <FontAwesomeIcon :icon="item.icon" :class="ICON_SIZE.md" fixed-width />
        <span>{{ item.label }}</span>
      </RouterLink>
    </nav>
  </div>
</template>
