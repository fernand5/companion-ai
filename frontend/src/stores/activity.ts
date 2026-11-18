import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as activityService from '@/services/activity'
import * as workoutService from '@/services/workouts'
import type { ActivityLog, WorkoutSession } from '@/types'
import type { LogActivityPayload } from '@/services/activity'
import type { LogWorkoutPayload } from '@/services/workouts'
import { errorRef } from '@/utils/errors'

export const useActivityStore = defineStore('activity', () => {
  const logs = ref<ActivityLog[]>([])
  const workouts = ref<WorkoutSession[]>([])
  const loading = ref(false)
  const failure = errorRef()
  const error = failure.message

  async function load() {
    loading.value = true
    failure.clear()

    try {
      const [logsResult, workoutsResult] = await Promise.all([
        activityService.fetchActivity(),
        workoutService.fetchWorkouts(),
      ])
      logs.value = logsResult
      workouts.value = workoutsResult
    } catch (e: unknown) {
      failure.fail(e, 'activityLoad')
    } finally {
      loading.value = false
    }
  }

  async function log(payload: LogActivityPayload) {
    const created = await activityService.logActivity(payload)
    logs.value.unshift(created)
  }

  async function logWorkout(payload: LogWorkoutPayload) {
    const created = await workoutService.logWorkout(payload)
    workouts.value.unshift(created)
  }

  async function remove(id: number) {
    await activityService.deleteActivity(id)
    logs.value = logs.value.filter((l) => l.id !== id)
  }

  return { logs, workouts, loading, error, load, log, logWorkout, remove }
})
