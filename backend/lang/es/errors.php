<?php

/*
 * Neutral Latin American Spanish (tú). Same keys and the same :placeholders as lang/en/errors.php.
 */
return [
    'http' => [
        'unauthenticated' => 'No has iniciado sesión.',
        'forbidden' => 'No tienes permiso para hacer esto.',
        'not_found' => 'No se encontró lo que buscas.',
        'method_not_allowed' => 'Método no permitido.',
        'too_many_attempts' => 'Demasiados intentos. Inténtalo de nuevo en un momento.',
        'server_error' => 'Error del servidor. Inténtalo de nuevo en un momento.',
    ],

    'auth' => [
        'logged_out' => 'Sesión cerrada.',
    ],

    'coach' => [
        'busy' => 'Tu coach sigue trabajando en tu mensaje anterior; espera primero su respuesta.',
    ],

    'provider' => [
        'not_configured' => 'El coach de IA aún no está configurado. Define AI_API_KEY en el archivo .env del backend.',
        'rate_limited' => 'Tu coach alcanzó su límite de solicitudes por ahora; espera un momento e inténtalo de nuevo.',
        'unavailable' => 'El coach de IA no está disponible por el momento; inténtalo de nuevo en un momento.',
    ],

    'exercise_demo' => [
        'unavailable' => 'No hay una demostración disponible para este ejercicio.',
        'failed' => 'La demostración del ejercicio no está disponible en este momento.',
    ],

    'progression' => [
        'unavailable' => 'Las sugerencias de progresión no están disponibles por el momento. Inténtalo de nuevo más tarde.',
    ],

    'weekly_plan' => [
        'already_covered' => 'Esta semana ya refleja tu horario recurrente.',
        'incomplete_decision' => 'No se pudo actualizar el plan semanal porque la decisión estaba incompleta. Inténtalo de nuevo.',
        'nothing_changed' => 'No se cambió nada en tu plan.',
        'failed' => 'No se pudo actualizar el plan semanal. Revisa los cambios e inténtalo de nuevo.',
    ],

    'validation' => [
        'only_proposal' => 'Solo se puede enviar la propuesta.',
        'set_needs_value' => 'Cada serie necesita repeticiones, peso o duración.',
        'plan_has_performance' => 'Este día ya tiene un entrenamiento registrado, por lo que su plan ya no se puede reemplazar.',
        'plan_started' => 'El entrenamiento de este día ya se inició o se completó, por lo que su plan ya no se puede reemplazar.',
        'exercise_not_pending' => 'Este ejercicio ya no está pendiente, por lo que su objetivo ya no se puede cambiar.',
        'record_one_set' => 'Registra al menos una serie completada, o marca el ejercicio como omitido.',
        'date_in_future' => 'La fecha de registro no puede estar en el futuro: esto registra algo que ya ocurrió. Las sesiones próximas van en el horario o en el plan.',
        'date_outside_range' => 'La fecha :date está fuera de los días que se pueden planificar (:start a :end).',
        'date_outside_week' => 'La fecha :date está fuera de la semana actual (:start a :end).',
    ],
];
