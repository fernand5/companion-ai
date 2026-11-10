import type { MessageSchema } from '@/i18n/messages/en'

/** Neutral Latin American Spanish, addressing the user as «tú». No country-specific slang. */
export const es: MessageSchema = {
  language: {
    label: 'Idioma',
    help: 'El idioma en el que lees la aplicación. Tus entrenamientos, números y unidades nunca cambian.',
    guestHelp: 'Se usa en esta pantalla. Tu cuenta conserva su propio idioma guardado cuando inicias sesión.',
    registerHelp: 'Este será el idioma de tu cuenta. Puedes cambiarlo después en tu perfil.',
    options: {
      en: 'English',
      es: 'Español',
    },
    saving: 'Guardando…',
    saved: 'Guardado',
    saveFailed: 'No pudimos guardar tu idioma. Inténtalo de nuevo.',
  },
}
