<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { onMounted } from 'vue'

import AppShell from '@/components/AppShell.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

onMounted(() => {
  auth.fetchCurrentUser()
})
</script>

<template>
  <div v-if="auth.initializing" class="flex h-screen items-center justify-center gap-2 text-slate-400">
    <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.md" spin />
    Loading…
  </div>
  <AppShell v-else-if="auth.isAuthenticated">
    <RouterView />
  </AppShell>
  <RouterView v-else />
</template>
