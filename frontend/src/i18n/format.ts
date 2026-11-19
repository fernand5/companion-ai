import { currentLocale, type AppLocale } from '@/i18n'

/**
 * Every date, time and number the user reads goes through here, so it follows the language the user
 * chose (not the browser's) and stays consistent. These functions only change how a value is SHOWN:
 * the stored number, date or unit is never converted or rounded unless a caller asks for fixed digits.
 * Units stay as stored (kilograms, kilometers).
 *
 * Each one reads the current language when it is called, so a render or computed that uses it
 * updates by itself when the language changes.
 */

/** The BCP 47 tag used for Intl. Spanish is formatted for Latin America (es-419). */
export function intlTag(locale: AppLocale = currentLocale()): string {
  return locale === 'es' ? 'es-419' : 'en-US'
}

const formatters = new Map<string, Intl.DateTimeFormat | Intl.NumberFormat>()

function dateFormatter(options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
  const key = `d|${intlTag()}|${JSON.stringify(options)}`
  let formatter = formatters.get(key) as Intl.DateTimeFormat | undefined

  if (!formatter) {
    formatter = new Intl.DateTimeFormat(intlTag(), options)
    formatters.set(key, formatter)
  }

  return formatter
}

function numberFormatter(options: Intl.NumberFormatOptions): Intl.NumberFormat {
  const key = `n|${intlTag()}|${JSON.stringify(options)}`
  let formatter = formatters.get(key) as Intl.NumberFormat | undefined

  if (!formatter) {
    formatter = new Intl.NumberFormat(intlTag(), options)
    formatters.set(key, formatter)
  }

  return formatter
}

/** A calendar date "YYYY-MM-DD", shown as the same day wherever the reader is (no timezone shift). */
export function formatDateOnly(date: string, options: Intl.DateTimeFormatOptions): string {
  return dateFormatter(options).format(new Date(`${date}T00:00:00`))
}

/** An instant (e.g. an ISO timestamp), shown in the reader's own timezone. */
export function formatDateTime(value: string | number | Date, options: Intl.DateTimeFormatOptions): string {
  return dateFormatter(options).format(new Date(value))
}

/** A clock time "HH:MM" (or "HH:MM:SS") such as a scheduled start. */
export function formatTimeOfDay(time: string): string {
  const [hours, minutes] = time.split(':')
  const date = new Date()
  date.setHours(Number(hours), Number(minutes))

  return dateFormatter({ hour: 'numeric', minute: '2-digit' }).format(date)
}

/** Day of the week by number, 0 = Sunday … 6 = Saturday, as the schedule stores it. */
export function weekdayName(dayOfWeek: number, style: 'short' | 'long' = 'short'): string {
  if (!Number.isInteger(dayOfWeek) || dayOfWeek < 0 || dayOfWeek > 6) return '?'

  // 2023-01-01 was a Sunday. UTC is only a stable container for the weekday here.
  return dateFormatter({ weekday: style, timeZone: 'UTC' }).format(new Date(Date.UTC(2023, 0, 1 + dayOfWeek)))
}

/** A number with the digits the caller asks for ("7.5" / "7,5"). Rounds only as far as the options say. */
export function formatDecimal(value: number, options: Intl.NumberFormatOptions = {}): string {
  return numberFormatter(options).format(value)
}

/** A whole number with the language's thousands separator, e.g. a step count. */
export function formatCount(value: number): string {
  return numberFormatter({ maximumFractionDigits: 0 }).format(value)
}

/**
 * A recorded measurement (a load in kg, a distance in km) shown with every digit it has and no
 * grouping, so display never rounds or reshapes what was stored: 42.5 → "42.5" / "42,5".
 */
export function formatMeasure(value: number): string {
  if (!Number.isFinite(value)) return String(value)

  const text = String(value)
  const digits = text.includes('e') ? 20 : (text.split('.')[1] ?? '').length

  return numberFormatter({ useGrouping: false, minimumFractionDigits: 0, maximumFractionDigits: Math.min(digits, 20) }).format(value)
}
