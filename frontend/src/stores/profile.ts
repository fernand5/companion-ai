import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as profileService from '@/services/profile'
import * as scheduleService from '@/services/schedule'
import type { FitnessProfile, TrainingSchedule } from '@/types'
import { extractErrorMessage } from '@/utils/errors'

export const useProfileStore = defineStore('profile', () => {
  const profile = ref<FitnessProfile | null>(null)
  const schedule = ref<TrainingSchedule[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

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
    } catch (e: unknown) {
      error.value = extractErrorMessage(e, "Couldn't load your profile.")
    } finally {
      loading.value = false
    }
  }

  async function save(payload: Partial<FitnessProfile>) {
    profile.value = await profileService.updateProfile(payload)
  }

  async function addScheduleEntry(payload: Omit<TrainingSchedule, 'id'>) {
    const created = await scheduleService.createSchedule(payload)
    schedule.value.push(created)
  }

  async function removeScheduleEntry(id: number) {
    await scheduleService.deleteSchedule(id)
    schedule.value = schedule.value.filter((s) => s.id !== id)
  }

  return { profile, schedule, loading, error, load, save, addScheduleEntry, removeScheduleEntry }
})
