import { computed, watchEffect } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

/** The page name for each route, by route name. A route this does not know is titled with the brand alone. */
const PAGE_KEYS = {
  login: 'auth.login.pageTitle',
  register: 'auth.register.pageTitle',
  dashboard: 'nav.items.dashboard',
  'weekly-plan': 'nav.items.weeklyPlan',
  coach: 'nav.items.coach',
  activity: 'nav.items.activity',
  progress: 'nav.items.progress',
  profile: 'nav.items.profile',
} as const

/**
 * Keeps the browser tab's title in the language on screen: "Perfil · Companion AI Coach". It re-runs when either the
 * page or the language changes, so a language switch updates it at once. Only the title text changes; the route
 * itself (and who may open it) is untouched.
 */
export function useDocumentTitle() {
  const { t } = useI18n()
  const route = useRoute()

  const title = computed(() => {
    const key = PAGE_KEYS[route.name as keyof typeof PAGE_KEYS]

    return key ? `${t(key)} · ${t('nav.brand')}` : t('nav.brand')
  })

  watchEffect(() => {
    document.title = title.value
  })

  return title
}
