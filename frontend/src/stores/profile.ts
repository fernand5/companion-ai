import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as profileService from '@/services/profile'
import * as scheduleService from '@/services/schedule'
import type { FitnessProfile, TrainingSchedule } from '@/types'

export const useProfileStore = defineStore('profile', () => {
  const profile = ref<FitnessProfile | null>(null)
  const schedule = ref<TrainingSchedule[]>([])
  const loading = ref(false)

  async function load() {
    loading.value = true

    try {
      const [profileResult, scheduleResult] = await Promise.all([
        profileService.fetchProfile(),
        scheduleService.fetchSchedule(),
      ])
      profile.value = profileResult
      schedule.value = scheduleResult
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

  return { profile, schedule, loading, load, save, addScheduleEntry, removeScheduleEntry }
})
