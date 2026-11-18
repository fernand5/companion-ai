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
export const en = { language, common, nav, auth, profile, coaching, errors, a11y }

export type MessageSchema = typeof en
