import { activity } from '@/i18n/messages/en/activity'
import { dashboard } from '@/i18n/messages/en/dashboard'
import { enums } from '@/i18n/messages/en/enums'
import { recovery } from '@/i18n/messages/en/recovery'
import { todaysPlan } from '@/i18n/messages/en/todaysPlan'
import { units } from '@/i18n/messages/en/units'
import { progress } from '@/i18n/messages/en/progress'
import { progression } from '@/i18n/messages/en/progression'
import { weeklyPlan } from '@/i18n/messages/en/weeklyPlan'
import { coach } from '@/i18n/messages/en/coach'
import { a11y } from '@/i18n/messages/en/a11y'
import { auth } from '@/i18n/messages/en/auth'
import { coaching } from '@/i18n/messages/en/coaching'
import { common } from '@/i18n/messages/en/common'
import { errors } from '@/i18n/messages/en/errors'
import { language } from '@/i18n/messages/en/language'
import { nav } from '@/i18n/messages/en/nav'
import { profile } from '@/i18n/messages/en/profile'

/**
 * English is the source of truth for the message shape. `es` must provide every key (the compiler checks it, and a
 * test checks the placeholders), and anything missing at runtime falls back to these strings.
 *
 * Organised by domain; each domain is one file here and one in ../es.
 */
export const en = { language, common, nav, auth, profile, coaching, errors, a11y, enums, units, dashboard, todaysPlan, recovery, activity, weeklyPlan, progress, progression, coach }

export type MessageSchema = typeof en
