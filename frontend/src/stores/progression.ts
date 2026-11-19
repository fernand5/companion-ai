import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as progressionService from '@/services/progression'
import type { ProgressionItem, ProgressionList } from '@/types'
import { i18n } from '@/i18n'
import { formatMeasure } from '@/i18n/format'
import { errorRef, errorState, describeError, type ErrorState } from '@/utils/errors'

/** What happened (or is happening) to one exercise's recommendation on this screen. */
export type ProgressionActionStatus = 'idle' | 'working' | 'applied' | 'kept' | 'stale' | 'failed'

export interface ProgressionActionState {
  status: ProgressionActionStatus
  message: string | null
}

/**
 * What is kept for one card. The words are NOT kept: a note the application writes is stored as what it is (a kind
 * and the values it names) and a failure as the failure (see ErrorState), and `stateFor` words them when read, so
 * they follow the language on screen. A message the server wrote is kept as it came.
 */
interface StoredState {
  status: ProgressionActionStatus
  note?: { key: 'applied' | 'kept'; params: Record<string, string> }
  failure?: ErrorState
}

const IDLE: ProgressionActionState = { status: 'idle', message: null }

function worded(stored: StoredState): ProgressionActionState {
  if (stored.note) return { status: stored.status, message: i18n.global.t(`progression.notes.${stored.note.key}`, stored.note.params) }
  if (stored.failure) return { status: stored.status, message: describeError(stored.failure) }

  return { status: stored.status, message: null }
}

export const useProgressionStore = defineStore('progression', () => {
  const items = ref<ProgressionItem[]>([])
  const summary = ref<ProgressionList['summary'] | null>(null)
  const loading = ref(false)
  const failure = errorRef()
  const error = failure.message
  /** Keyed by exercise name: the server's slug can be null for an unusable name. */
  const actions = ref<Record<string, StoredState>>({})

  function stateFor(name: string): ProgressionActionState {
    const stored = actions.value[name]

    return stored ? worded(stored) : IDLE
  }

  function setState(name: string, state: StoredState) {
    actions.value = { ...actions.value, [name]: state }
  }

  /**
   * Fetch fresh recommendations. A card whose proposal went stale is replaced by the new one, and
   * the "kept" and "stale" notes are cleared with it (the server's own record of a kept target takes over); a just-applied note stays so the user sees
   * what happened.
   */
  async function load() {
    loading.value = true
    failure.clear()

    try {
      const list = await progressionService.fetchRecommendations()
      items.value = list.data
      summary.value = list.summary ?? null
      actions.value = Object.fromEntries(Object.entries(actions.value).filter(([, state]) => state.status === 'applied'))
    } catch (e: unknown) {
      failure.fail(e, 'progressionLoad')
    } finally {
      loading.value = false
    }
  }

  /** Approve the recommendation shown. Never sends anything but the server's own proposal. */
  async function accept(item: ProgressionItem): Promise<boolean> {
    const name = item.exercise.name

    if (!item.proposal || stateFor(name).status === 'working') return false

    setState(name, { status: 'working' })

    try {
      const applied = await progressionService.approveProposal(item.proposal.id)
      // The date stays in its ISO form: it is the same, unambiguous text in every language.
      setState(name, { status: 'applied', note: { key: 'applied', params: { name: applied.exercise.name, kg: formatMeasure(Number(applied.to_kg)), date: applied.date } } })
      await load()

      return true
    } catch (e: unknown) {
      const status = (e as { response?: { status?: number } })?.response?.status
      const failed = errorState(e, 'progressionApply')

      // 409: the plan, the record or the safety picture moved, or the proposal was used/expired.
      // The answer is always a fresh recommendation, never a retry of the old one.
      setState(name, { status: status === 409 || status === 422 ? 'stale' : 'failed', failure: failed })

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

    setState(name, { status: 'working' })

    try {
      await progressionService.keepCurrentTarget(item.proposal.id)
      setState(name, { status: 'kept', note: { key: 'kept', params: { name } } })
      await load()

      return true
    } catch (e: unknown) {
      setState(name, { status: 'failed', failure: errorState(e, 'progressionKeep') })

      return false
    }
  }

  return { items, summary, loading, error, actions, stateFor, load, accept, keep }
})
