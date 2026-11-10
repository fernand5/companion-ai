<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { ICON_SIZE, icons } from '@/constants/icons'
import { SUPPORTED_LOCALES, type AppLocale } from '@/i18n'
import { useLocaleStore } from '@/stores/locale'

/**
 * The language picker. `account` (Profile) saves the choice on the account; `guest` (sign-in and sign-up)
 * only changes this screen, so it can never replace a saved language. `register` is a guest picker whose
 * choice becomes the new account's language. The language names are written in their own language
 * ("Español") and are never translated, so a reader can always find theirs.
 */
const props = withDefaults(defineProps<{ scope?: 'account' | 'guest' | 'register' }>(), { scope: 'account' })

const { t } = useI18n()
const store = useLocaleStore()
const headingId = useId()

const help = computed(() => {
  if (props.scope === 'guest') return t('language.guestHelp')
  if (props.scope === 'register') return t('language.registerHelp')

  return t('language.help')
})

const showSaved = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

watch(
  () => store.justSaved,
  (value) => {
    clearTimeout(timer)
    showSaved.value = value
    if (value) timer = setTimeout(() => (showSaved.value = false), 2500)
  },
)

onBeforeUnmount(() => {
  clearTimeout(timer)
  store.clearStatus()
})

function select(locale: AppLocale) {
  void store.choose(locale)
}

function optionClasses(selected: boolean) {
  return selected
    ? 'border-brand-600 bg-brand-50 ring-1 ring-brand-600'
    : 'border-slate-200 bg-white hover:border-slate-300'
}
</script>

<template>
  <section class="space-y-2" :aria-labelledby="headingId" data-testid="language-selector">
    <div>
      <h2 :id="headingId" class="flex items-center gap-2 text-sm font-semibold text-slate-900">
        <FontAwesomeIcon :icon="icons.language" :class="ICON_SIZE.sm" />
        {{ t('language.label') }}
      </h2>
      <p class="mt-0.5 text-xs text-slate-500">{{ help }}</p>
    </div>

    <fieldset :disabled="store.saving" class="grid grid-cols-2 gap-2">
      <legend class="sr-only">{{ t('language.label') }}</legend>
      <label
        v-for="option in SUPPORTED_LOCALES"
        :key="option"
        class="flex min-h-11 cursor-pointer items-center justify-center rounded-lg border px-3 py-2 text-sm font-medium text-slate-900 focus-within:ring-2 focus-within:ring-brand-500 has-[:disabled]:cursor-wait has-[:disabled]:opacity-70"
        :class="optionClasses(store.locale === option)"
        :data-testid="`language-option-${option}`"
      >
        <input
          type="radio"
          name="app-language"
          class="sr-only"
          :value="option"
          :checked="store.locale === option"
          :lang="option"
          @change="select(option)"
        />
        <span :lang="option">{{ t(`language.options.${option}`, {}, { locale: option }) }}</span>
      </label>
    </fieldset>

    <!-- Reserve a line for the status so the form below does not jump when it appears. -->
    <div class="min-h-4">
      <p v-if="store.saving" class="text-xs text-slate-500" role="status">{{ t('language.saving') }}</p>
      <p v-else-if="showSaved" class="text-xs text-brand-700" role="status" data-testid="language-saved">
        {{ t('language.saved') }}
      </p>
      <p
        v-if="store.failed"
        class="flex items-center gap-1.5 text-xs text-red-600"
        role="alert"
        data-testid="language-error"
      >
        <FontAwesomeIcon :icon="icons.warning" :class="ICON_SIZE.sm" />
        {{ t('language.saveFailed') }}
      </p>
    </div>
  </section>
</template>
