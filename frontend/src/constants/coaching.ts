import type { CoachingPreferences, CoachStyle, ResponseDetail } from '@/types'

/** What a user who never chose gets (matches the server's defaults). */
export const DEFAULT_COACHING: CoachingPreferences = {
  coach_style: 'balanced',
  response_detail: 'standard',
}

/**
 * The identifiers the server stores, in the order they are offered. They never change with the language. Everything a
 * person reads about them (label, description, example) is a translation key under `coaching.*`, looked up when it is
 * drawn, so it follows the language on screen.
 */
export const COACH_STYLE_VALUES: ReadonlyArray<CoachStyle> = ['supportive', 'balanced', 'intense']

export const RESPONSE_DETAIL_VALUES: ReadonlyArray<ResponseDetail> = ['concise', 'standard', 'detailed']
