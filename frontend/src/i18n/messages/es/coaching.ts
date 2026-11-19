import type { Coaching } from '@/i18n/messages/en/coaching'

/**
 * Neutral Latin American Spanish (tú). The samples say the same thing as the English ones: rest the sore knee, do not
 * make up the missed session, keep the planned loads, and see a professional if it persists. Only the voice differs.
 */
export const coaching: Coaching = {
  title: 'Tu coach',
  intro:
    'Elige cómo te habla tu coach. Esto cambia solo la forma de decirlo: tu plan, tu recuperación y las indicaciones de seguridad siguen siendo las mismas.',
  savedValues: 'Guardado: estilo {style}, detalle {detail}',
  styleLegend: 'Estilo de coaching',
  detailLegend: 'Nivel de detalle de las respuestas',
  detailNote:
    'El detalle cambia solo la extensión. Las advertencias de dolor y de seguridad, y lo que no se ha registrado, nunca se omiten.',
  savedBadge: 'Guardado',
  save: 'Guardar estilo de coaching',
  saving: 'Guardando…',
  saved: 'Guardado.',
  example: {
    title: 'Ejemplo de respuesta',
    you: 'Tú: “{prompt}”',
    coach: 'Coach: “{reply}”',
    fixed: 'Un ejemplo fijo, no escrito por la IA.',
  },
  styles: {
    supportive: {
      label: 'De apoyo',
      description: 'Empático, alentador y paciente. Va a tu ritmo.',
    },
    balanced: {
      label: 'Equilibrado',
      description: 'Práctico, motivador y directo. Indicaciones claras sobre qué hacer y por qué.',
    },
    intense: {
      label: 'Intenso',
      description: 'Enérgico, exigente y que te pide cuentas. Te mantiene fiel a tu plan.',
    },
  },
  details: {
    concise: { label: 'Conciso', description: 'Respuestas cortas: lo esencial y un siguiente paso.' },
    standard: { label: 'Estándar', description: 'La respuesta, un par de razones y el siguiente paso.' },
    detailed: { label: 'Detallado', description: 'Un razonamiento más completo a partir de tus datos registrados.' },
  },
  samplePrompt: 'Me perdí el entrenamiento del martes y me duele un poco la rodilla.',
  samples: {
    supportive: {
      concise:
        'Está bien, pasa. Con la rodilla adolorida, descansa hoy y retoma poco a poco cuando te sientas mejor.',
      standard:
        'Está totalmente bien, es normal faltar un día y escuchar a tu cuerpo es la decisión correcta. Descansa la rodilla hoy y mira cómo te sientes mañana. Si estás mejor, retomaremos con la sesión del jueves tal como está planeada.',
      detailed:
        'Está totalmente bien, es normal faltar un día y escuchar a tu cuerpo es la decisión correcta. Tu rodilla es la prioridad: descansa hoy y fíjate cómo amanece mañana. Si se calma, retomaremos con la sesión del jueves exactamente como está planeada, sin recuperar nada. Si todavía te molesta, mantendremos todo ligero y suave, y conviene consultar a un profesional si persiste o empeora.',
    },
    balanced: {
      concise:
        'Olvídate de recuperarla. Descansa la rodilla hoy; si mañana se siente bien, haz el jueves como estaba planeado.',
      standard:
        'No hace falta reponerlo. Descansa la rodilla hoy y mira cómo se siente mañana. Si está bien, haz la sesión del jueves como estaba planeada; si no, la mantendremos ligera.',
      detailed:
        'No hace falta reponer el martes, y más volumen ahora no ayudaría a una rodilla adolorida. Descansa hoy y revisa cómo se siente mañana por la mañana. Si está bien, haz el jueves como estaba planeado. Si sigue adolorida, cambiaremos a trabajo de menor impacto y mantendremos tus cargas donde están. Si persiste o empeora, haz que la revisen.',
    },
    intense: {
      concise:
        'Sesión perdida anotada. Sin duplicar entrenamientos y sin entrenar con dolor de rodilla: descansa hoy y el jueves, a entrenar.',
      standard:
        'Sesión perdida anotada, y ya quedó atrás. Sin duplicar entrenamientos y sin entrenar con dolor de rodilla: descansa hoy, revisa la rodilla mañana y, si está bien, el jueves a trabajar con las cargas planeadas.',
      detailed:
        'Sesión perdida anotada, y ya quedó atrás. Este es el estándar: sin duplicar entrenamientos para compensar y sin entrenar con dolor de rodilla. Descansa hoy y revisa la rodilla mañana. Si está bien, el jueves a trabajar con las cargas planeadas, no con más. Si sigue adolorida, ajustamos el plan en lugar de forzar, y si persiste, haz que la revisen. Responsabilidad significa hacer bien el plan, no hacer de más.',
    },
  },
}
