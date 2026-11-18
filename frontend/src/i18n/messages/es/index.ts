import type { MessageSchema } from '@/i18n/messages/en'
import { a11y } from '@/i18n/messages/es/a11y'
import { auth } from '@/i18n/messages/es/auth'
import { coaching } from '@/i18n/messages/es/coaching'
import { common } from '@/i18n/messages/es/common'
import { errors } from '@/i18n/messages/es/errors'
import { language } from '@/i18n/messages/es/language'
import { nav } from '@/i18n/messages/es/nav'
import { profile } from '@/i18n/messages/es/profile'

/** Neutral Latin American Spanish, addressing the user as «tú». No country-specific slang. */
export const es: MessageSchema = { language, common, nav, auth, profile, coaching, errors, a11y }
