import type { Language } from '@/i18n/messages/en/language'

export const language: Language = {
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
}
