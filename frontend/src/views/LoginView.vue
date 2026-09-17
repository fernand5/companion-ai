<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { ICON_SIZE, icons } from '@/constants/icons'
import { useAuthStore } from '@/stores/auth'
import { extractErrorMessage } from '@/utils/errors'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('demo@example.com')
const password = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)

async function handleSubmit() {
  error.value = null
  submitting.value = true

  try {
    await auth.login({ email: email.value, password: password.value })
    const redirect = (route.query.redirect as string) || '/'
    router.push(redirect)
  } catch (e: unknown) {
    // A hardcoded "credentials not recognized" here would be actively
    // misleading for anything that isn't a wrong password — a rate limit
    // (429, after repeated attempts) or the backend being briefly
    // unreachable would show the exact same message, sending someone to
    // second-guess a password that was never the problem.
    error.value = extractErrorMessage(e, "Couldn't log in — please try again.")
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-50 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-sm">
      <h1 class="text-xl font-semibold text-slate-900">Welcome back</h1>
      <p class="mt-1 text-sm text-slate-500">Log in to keep training with your coach.</p>

      <form class="mt-6 space-y-4" @submit.prevent="handleSubmit">
        <div>
          <label class="block text-sm font-medium text-slate-700" for="email">Email</label>
          <input
            id="email"
            v-model="email"
            type="email"
            required
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700" for="password">Password</label>
          <input
            id="password"
            v-model="password"
            type="password"
            required
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
          />
        </div>

        <p v-if="error" class="flex items-center gap-1.5 text-sm text-red-600">
          <FontAwesomeIcon :icon="icons.warning" :class="ICON_SIZE.sm" />
          {{ error }}
        </p>

        <button
          type="submit"
          :disabled="submitting"
          class="flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
        >
          <FontAwesomeIcon v-if="submitting" :icon="icons.loading" :class="ICON_SIZE.sm" spin />
          {{ submitting ? 'Logging in…' : 'Log in' }}
        </button>
      </form>

      <p class="mt-4 text-center text-sm text-slate-500">
        No account?
        <RouterLink :to="{ name: 'register' }" class="font-medium text-brand-600">
          Register
        </RouterLink>
      </p>
    </div>
  </div>
</template>
