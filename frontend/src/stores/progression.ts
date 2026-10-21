import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as progressionService from '@/services/progression'
import type { ProgressionItem } from '@/types'
import { extractErrorMessage } from '@/utils/errors'

/** What happened (or is happening) to one exercise's recommendation on this screen. */
export type ProgressionActionStatus = 'idle' | 'working' | 'applied' | 'kept' | 'stale' | 'failed'

export interface ProgressionActionState {
  status: ProgressionActionStatus
  message: string | null
}

const IDLE: ProgressionActionState = { status: 'idle', message: null }

export const useProgressionStore = defineStore('progression', () => {
  const items = ref<ProgressionItem[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  /** Keyed by exercise name: the server's slug can be null for an unusable name. */
  const actions = ref<Record<string, ProgressionActionState>>({})

  function stateFor(name: string): ProgressionActionState {
    return actions.value[name] ?? IDLE
  }

  function setState(name: string, state: ProgressionActionState) {
    actions.value = { ...actions.value, [name]: state }
  }

  /**
   * Fetch fresh recommendations. A card whose proposal went stale is replaced by the new one, and
   * the "kept" and "stale" notes are cleared with it (the server's own record of a kept target takes over); a just-applied note stays so the user sees
   * what happened.
   */
  async function load() {
    loading.value = true
    error.value = null

    try {
      const list = await progressionService.fetchRecommendations()
      items.value = list.data
      actions.value = Object.fromEntries(Object.entries(actions.value).filter(([, state]) => state.status === 'applied'))
    } catch (e: unknown) {
      error.value = extractErrorMessage(e, "Couldn't load your progression recommendations.")
    } finally {
      loading.value = false
    }
  }

  /** Approve the recommendation shown. Never sends anything but the server's own proposal. */
  async function accept(item: ProgressionItem): Promise<boolean> {
    const name = item.exercise.name

    if (!item.proposal || stateFor(name).status === 'working') return false

    setState(name, { status: 'working', message: null })

    try {
      const applied = await progressionService.approveProposal(item.proposal.id)
      setState(name, { status: 'applied', message: `${applied.exercise.name} is now ${Number(applied.to_kg)} kg for ${applied.date}.` })
      await load()

      return true
    } catch (e: unknown) {
      const status = (e as { response?: { status?: number } })?.response?.status
      const message = extractErrorMessage(e, "Couldn't apply that recommendation — please try again.")

      // 409: the plan, the record or the safety picture moved, or the proposal was used/expired.
      // The answer is always a fresh recommendation, never a retry of the old one.
      setState(name, { status: status === 409 || status === 422 ? 'stale' : 'failed', message })

      return false
    }
  }

  /**
   * "Keep current target". The server remembers it (for exactly this recommendation, until new sets or a
   * plan change make a new one), and nothing in the plan is changed. The list is reloaded so the card
   * shows what the server now says rather than what this tab assumes.
   */
  async function keep(item: ProgressionItem): Promise<boolean> {
    const name = item.exercise.name

    if (!item.proposal || stateFor(name).status === 'working') return false

    setState(name, { status: 'working', message: null })

    try {
      await progressionService.keepCurrentTarget(item.proposal.id)
      setState(name, { status: 'kept', message: `Keeping ${name} as planned.` })
      await load()

      return true
    } catch (e: unknown) {
      setState(name, { status: 'failed', message: extractErrorMessage(e, "Couldn't save that — please try again.") })

      return false
    }
  }

  return { items, loading, error, actions, stateFor, load, accept, keep }
})
