import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import * as profileService from '@/services/profile'
import * as scheduleService from '@/services/schedule'
import { DEFAULT_COACHING } from '@/constants/coaching'
import type { CoachingPreferences, FitnessProfile, TrainingSchedule } from '@/types'
import { describeError, errorState, type ErrorState } from '@/utils/errors'

export const useProfileStore = defineStore('profile', () => {
  const profile = ref<FitnessProfile | null>(null)
  const schedule = ref<TrainingSchedule[]>([])
  const loading = ref(false)
  // A failure is kept as the failure it is, and worded when read: a fallback follows the language on screen,
  // and a server message is shown as it came. Nothing here ever turns a failure into a success.
  const failure = ref<ErrorState | null>(null)
  const error = computed(() => (failure.value ? describeError(failure.value) : null))

  // How the AI coach sounds. Kept apart from `profile` on purpose: saving it must not replace the
  // profile object, because the profile form re-fills itself from that and would drop unsaved edits.
  const coaching = ref<CoachingPreferences>({ ...DEFAULT_COACHING })
  const savingCoaching = ref(false)
  const coachingFailure = ref<ErrorState | null>(null)
  const coachingError = computed(() => (coachingFailure.value ? describeError(coachingFailure.value) : null))

  async function load() {
    loading.value = true
    failure.value = null

    try {
      const [profileResult, scheduleResult] = await Promise.all([
        profileService.fetchProfile(),
        scheduleService.fetchSchedule(),
      ])
      profile.value = profileResult
      schedule.value = scheduleResult
      coaching.value = {
        coach_style: profileResult?.coach_style ?? DEFAULT_COACHING.coach_style,
        response_detail: profileResult?.response_detail ?? DEFAULT_COACHING.response_detail,
      }
    } catch (e: unknown) {
      failure.value = errorState(e, 'profileLoad')
    } finally {
      loading.value = false
    }
  }

  async function save(payload: Partial<FitnessProfile>) {
    profile.value = await profileService.updateProfile(payload)
  }

  /** Saves only the two coaching settings, so no other profile value can be touched. */
  async function saveCoaching(next: CoachingPreferences): Promise<boolean> {
    savingCoaching.value = true
    coachingFailure.value = null

    try {
      const saved = await profileService.updateProfile({
        coach_style: next.coach_style,
        response_detail: next.response_detail,
      })
      coaching.value = { coach_style: saved.coach_style, response_detail: saved.response_detail }

      return true
    } catch (e: unknown) {
      coachingFailure.value = errorState(e, 'coachingSave')

      return false
    } finally {
      savingCoaching.value = false
    }
  }

  async function addScheduleEntry(payload: Omit<TrainingSchedule, 'id'>) {
    const created = await scheduleService.createSchedule(payload)
    schedule.value.push(created)
  }

  async function removeScheduleEntry(id: number) {
    await scheduleService.deleteSchedule(id)
    schedule.value = schedule.value.filter((s) => s.id !== id)
  }

  return {
    profile,
    schedule,
    loading,
    error,
    coaching,
    savingCoaching,
    coachingError,
    load,
    save,
    saveCoaching,
    addScheduleEntry,
    removeScheduleEntry,
  }
})
