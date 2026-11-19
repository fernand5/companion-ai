<?php

/*
 * Neutral Latin American Spanish (tú). The same keys and :placeholders as lang/en/progression.php.
 */
return [
    'reasons' => [
        'NO_MEASURED_HISTORY' => 'Ninguna sesión de este ejercicio tiene series registradas.',
        'ONLY_ASSUMED_COMPLETIONS' => 'Este ejercicio solo se marcó como hecho con un toque, lo que registra el plan, no lo que se levantó.',
        'NEED_MORE_SESSIONS' => 'Hay menos de :sessions sesiones medidas de este ejercicio.',
        'STALE_HISTORY' => 'La última sesión medida tiene más de :stale_days días.',
        'LATEST_SESSION_NOT_MEASURED' => 'La sesión más reciente de este ejercicio no tiene series registradas (omitida, pendiente o marcada como hecha con un toque).',
        'MIXED_EXERCISE_IDENTITIES' => 'El historial mezcla ejercicios distintos, que nunca se combinan.',
        'MIGRATED_EVIDENCE' => 'La decisión se basaría en historial migrado, que puede estar incompleto.',
        'NO_TARGET_RECORDED' => 'No se registró un objetivo para estas sesiones, así que no se sabe si se cumplieron.',
        'TIMED_EXERCISE_UNSUPPORTED' => 'Este es un ejercicio por duración; la política no los progresa automáticamente.',
        'NO_LOAD_RECORDED' => 'No se registró ninguna carga, así que un ejercicio con peso corporal no se puede distinguir de un valor faltante.',
        'LOAD_PARTIALLY_RECORDED' => 'La carga se registró en algunas series completadas y en otras no.',
        'TARGETS_REACHED_CONSECUTIVELY' => 'El objetivo se alcanzó en cada una de las últimas :sessions sesiones con la misma carga.',
        'TARGET_NOT_REACHED' => 'La última sesión no alcanzó su objetivo.',
        'REPEATED_MISSES' => 'El objetivo no se alcanzó en sesiones consecutivas con la misma carga.',
        'MISSES_AT_SAME_LOAD' => 'El objetivo no se alcanzó en cada una de las últimas :misses sesiones con la misma carga.',
        'INCOMPLETE_SESSION' => 'La última sesión quedó incompleta.',
        'INCONSISTENT_RESULTS' => 'El objetivo se alcanzó en algunas sesiones recientes con esta carga y en otras no.',
        'CONSOLIDATE_AT_CURRENT_LOAD' => 'Hay menos de :sessions sesiones consecutivas con la carga y el objetivo actuales.',
        'LOAD_CHANGED_RECENTLY' => 'La carga cambió dentro de las últimas :sessions sesiones.',
        'SESSIONS_ON_TOO_FEW_DAYS' => 'Las sesiones no ocurrieron en :sessions días distintos.',
        'TARGET_ALREADY_AT_PROPOSED' => 'El objetivo actual ya es igual o superior a la carga que respalda el registro.',
        'CURRENT_TARGET_EXCEEDS_MAX_CHANGE' => 'El objetivo actual ya supera el cambio máximo permitido respecto a la carga registrada más pesada (es un tope, no una carga recomendada).',
        'LOAD_STEP_EXCEEDS_LIMIT' => 'El cambio de carga más pequeño disponible para este ejercicio supera el :percent% de la carga.',
        'PAIN_REPORTED_RECENTLY' => 'Un chequeo de recuperación de los últimos :pain_days días menciona dolor.',
        'HIGH_SORENESS' => 'El último chequeo de recuperación indica mucho dolor muscular.',
        'RESTRICTION_ON_RECORD' => 'La persona tiene una lesión, restricción o limitación guardada que no se puede asociar a un ejercicio.',
    ],

    'caveats' => [
        'effort_not_recorded' => 'No se registran el esfuerzo (RPE), las repeticiones en reserva ni la técnica: alcanzar un objetivo no demuestra que la carga se sintiera manejable.',
        'not_medical_clearance' => 'Esto es un cálculo a partir de un registro de entrenamiento, no una autorización médica ni una evaluación de preparación.',
        'recovery_unknown' => 'No hay chequeos de recuperación en los últimos :recovery_days días, así que la recuperación es desconocida.',
        'pain_reported' => 'Un chequeo de recuperación reciente menciona dolor. No agregues carga sin consultar a un profesional calificado si persiste.',
        'high_soreness' => 'El último chequeo de recuperación indica mucho dolor muscular.',
        'restriction_unmatched' => 'No se pudo asociar una lesión, restricción o limitación guardada a un ejercicio específico, así que una persona debe revisar si aplica o no.',
    ],

    'approval' => [
        'invalid_proposal' => 'Esa propuesta no es válida. Actualiza tus recomendaciones e inténtalo de nuevo.',
        'forbidden' => 'Esa propuesta no pertenece a esta cuenta.',
        'expired' => 'Esa recomendación venció. Actualiza para obtener una nueva.',
        'stale' => 'Tu plan o tu historial reciente cambió desde que se hizo esta recomendación. Actualiza para obtener una nueva.',
        'already_used' => 'Esa recomendación ya se usó. Actualiza para ver tu plan actual.',
        'dismissed' => 'Elegiste mantener este objetivo. Aparecerá una nueva recomendación si registras series nuevas o tu plan cambia.',
        'ineligible' => 'Esta recomendación ya no se puede aplicar (:detail). Actualiza para obtener una nueva.',
        'ineligible_unknown' => 'Esta recomendación ya no se puede aplicar. Actualiza para obtener una nueva.',
    ],

    'ineligible' => [
        'not_a_change' => 'no propone un cambio',
        'no_upcoming_session' => 'no hay una sesión próxima con este ejercicio',
        'no_load_in_plan' => 'el plan no tiene una carga para este ejercicio',
        'workout_already_started' => 'el entrenamiento ya se inició o ya no está disponible',
        'plan_already_at_or_above' => 'el plan ya tiene esa carga o una mayor',
        'above_maximum_change' => 'supera el cambio máximo permitido',
        'plan_already_at_or_below' => 'el plan ya tiene esa carga o una menor',
    ],
];
