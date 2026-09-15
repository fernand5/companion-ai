<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { ref } from 'vue'
import { useRouter } from 'vue-router'

import { ICON_SIZE, icons } from '@/constants/icons'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)

async function handleSubmit() {
  error.value = null
  submitting.value = true

  try {
    await auth.register({
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    router.push('/')
  } catch (e: unknown) {
    const message =
      (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    error.value = message ?? 'Could not create your account — check the fields and try again.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-50 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-sm">
      <h1 class="text-xl font-semibold text-slate-900">Create your account</h1>
      <p class="mt-1 text-sm text-slate-500">Start training with your adaptive AI coach.</p>

      <form class="mt-6 space-y-4" @submit.prevent="handleSubmit">
        <div>
          <label class="block text-sm font-medium text-slate-700" for="name">Name</label>
          <input
            id="name"
            v-model="name"
            type="text"
            required
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
          />
        </div>
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
            minlength="8"
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700" for="password_confirmation">
            Confirm password
          </label>
          <input
            id="password_confirmation"
            v-model="passwordConfirmation"
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
          {{ submitting ? 'Creating account…' : 'Register' }}
        </button>
      </form>

      <p class="mt-4 text-center text-sm text-slate-500">
        Already have an account?
        <RouterLink :to="{ name: 'login' }" class="font-medium text-brand-600">Log in</RouterLink>
      </p>
    </div>
  </div>
</template>
