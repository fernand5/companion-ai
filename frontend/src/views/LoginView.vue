<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import LanguageSelector from '@/components/LanguageSelector.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useAuthStore } from '@/stores/auth'
import { describeError, errorState, type ErrorState } from '@/utils/errors'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const { t } = useI18n()

const email = ref('demo@example.com')
const password = ref('')
// Kept as the failure it is, so a fallback is re-worded when the language changes (a server message is shown as it came).
const failure = ref<ErrorState | null>(null)
const error = computed(() => (failure.value ? describeError(failure.value) : null))
const submitting = ref(false)

async function handleSubmit() {
  failure.value = null
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
    failure.value = errorState(e, 'loginFailed')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-50 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-sm">
      <h1 class="text-xl font-semibold text-slate-900">{{ t('auth.login.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ t('auth.login.subtitle') }}</p>

      <form class="mt-6 space-y-4" @submit.prevent="handleSubmit">
        <div>
          <label class="block text-sm font-medium text-slate-700" for="email">{{ t('auth.login.email') }}</label>
          <input
            id="email"
            v-model="email"
            type="email"
            required
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700" for="password">{{ t('auth.login.password') }}</label>
          <input
            id="password"
            v-model="password"
            type="password"
            required
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
          />
        </div>

        <p v-if="error" class="flex items-center gap-1.5 text-sm text-red-600" role="alert">
          <FontAwesomeIcon :icon="icons.warning" :class="ICON_SIZE.sm" />
          {{ error }}
        </p>

        <button
          type="submit"
          :disabled="submitting"
          class="flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
        >
          <FontAwesomeIcon v-if="submitting" :icon="icons.loading" :class="ICON_SIZE.sm" spin />
          {{ submitting ? t('auth.login.submitting') : t('auth.login.submit') }}
        </button>
      </form>

      <div class="mt-6 border-t border-slate-100 pt-4">
        <LanguageSelector scope="guest" />
      </div>

      <p class="mt-4 text-center text-sm text-slate-500">
        {{ t('auth.login.noAccount') }}
        <RouterLink :to="{ name: 'register' }" class="font-medium text-brand-600">
          {{ t('auth.login.register') }}
        </RouterLink>
      </p>
    </div>
  </div>
</template>
