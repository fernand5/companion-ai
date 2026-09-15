import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as recoveryService from '@/services/recovery'
import type { SubmitCheckinPayload } from '@/services/recovery'
import type { RecoveryCheckin } from '@/types'

export const useRecoveryStore = defineStore('recovery', () => {
  const today = ref<RecoveryCheckin | null>(null)
  const loading = ref(false)

  async function loadToday() {
    loading.value = true

    try {
      today.value = await recoveryService.fetchTodayCheckin()
    } finally {
      loading.value = false
    }
  }

  async function submit(payload: SubmitCheckinPayload) {
    today.value = await recoveryService.submitCheckin(payload)
  }

  return { today, loading, loadToday, submit }
})
