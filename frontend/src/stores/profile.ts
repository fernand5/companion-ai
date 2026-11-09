import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as profileService from '@/services/profile'
import * as scheduleService from '@/services/schedule'
import { DEFAULT_COACHING } from '@/constants/coaching'
import type { CoachingPreferences, FitnessProfile, TrainingSchedule } from '@/types'
import { extractErrorMessage } from '@/utils/errors'

export const useProfileStore = defineStore('profile', () => {
  const profile = ref<FitnessProfile | null>(null)
  const schedule = ref<TrainingSchedule[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  // How the AI coach sounds. Kept apart from `profile` on purpose: saving it must not replace the
  // profile object, because the profile form re-fills itself from that and would drop unsaved edits.
  const coaching = ref<CoachingPreferences>({ ...DEFAULT_COACHING })
  const savingCoaching = ref(false)
  const coachingError = ref<string | null>(null)

  async function load() {
    loading.value = true
    error.value = null

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
      error.value = extractErrorMessage(e, "Couldn't load your profile.")
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
    coachingError.value = null

    try {
      const saved = await profileService.updateProfile({
        coach_style: next.coach_style,
        response_detail: next.response_detail,
      })
      coaching.value = { coach_style: saved.coach_style, response_detail: saved.response_detail }

      return true
    } catch (e: unknown) {
      coachingError.value = extractErrorMessage(e, "Couldn't save your coaching style. Please try again.")

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
