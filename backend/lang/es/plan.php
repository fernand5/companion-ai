<?php

/*
 * Cómo la aplicación describe al usuario los cambios del plan (Phase 7 / M3b): reemplazos en espera, planes de varios
 * días guardados y los motivos por los que un cambio no pudo hacerse. Solo texto de presentación: las propuestas
 * firmadas y todo lo almacenado conservan sus campos intactos; estas frases se construyen a partir de ellos ya verificados.
 */

return [
    'rest_day' => 'un día de descanso',
    'rest_day_short' => 'día de descanso',
    'nothing_planned' => 'nada planificado',

    'replacement' => [
        'single_clear' => 'No he cambiado tu plan. El :date ya tiene :existing. ¿Quieres que lo convierta en día de descanso? Responde ":yes" en los próximos :minutes minutos para hacer exactamente eso; cualquier otra respuesta lo deja como está.',
        'single_replace' => 'No he cambiado tu plan. El :date ya tiene :existing. ¿Quieres que lo reemplace por :replacement? Responde ":yes" en los próximos :minutes minutos para reemplazarlo exactamente por eso; cualquier otra respuesta lo deja como está.',
        'several_with_new_days' => 'Todavía no he cambiado tu plan. Guardar este plan haría estos cambios, y algunos de estos días ya tienen un plan que se reemplazaría:',
        'several_existing' => 'Todavía no he cambiado tu plan. Estos días ya tienen un plan:',
        'confirm_all' => '¿Quieres que haga todos estos cambios? Responde ":yes" en los próximos :minutes minutos para hacer exactamente estos cambios y nada más; cualquier otra respuesta deja tu plan como está.',
    ],

    'change' => [
        'create' => ':date: se añadiría :replacement (todavía no hay nada planificado)',
        'clear_new' => ':date: se marcaría como día de descanso (todavía no hay nada planificado)',
        'clear_existing' => ':date: :existing pasaría a ser un día de descanso',
        'replace' => ':date: :existing se reemplazaría por :replacement',
    ],

    'proposal' => [
        'set_target' => 'establezca :exercise en :kg kg:amount el :date',
    ],

    'week' => [
        'nothing_saved' => 'No se guardó nada de ningún día.',
        'saved_header' => 'Listo. Añadí esto a tu plan, todo junto, y comprobé que quedó guardado:',
        'day_line' => '- :day: :what',
        'already_one' => ':dates ya estaba en tu plan exactamente así, por lo que lo dejé como está.',
        'already_many' => ':dates ya estaban en tu plan exactamente así, por lo que los dejé como están.',
        'omitted' => 'Dejé fuera :omitted porque solo nombraste los días de arriba; escribe ":save_plan" de nuevo si quieres el resto.',
        'blocked' => 'No he cambiado tu plan y no guardé ningún día: :list no se puede reemplazar, y guardo un plan completo o nada, para que tu semana nunca quede a medias. Para añadir los demás días, responde solo con esos días, por ejemplo ":sunday" (o ":save_plan_sunday").',
        'blocked_item' => ':date (:label: :why)',
        'blocked_started' => 'ya empezó',
        'blocked_performed' => 'ya tiene un entrenamiento realizado y registrado',
        'already_everything' => 'Todo lo de ese plan (:dates) ya está en tu plan exactamente como se mostró, así que no había nada que cambiar.',
        'not_saved_because' => 'No he cambiado tu plan: :problem. No se guardó nada de ningún día. Pídemelo de nuevo con ":save_plan".',
        'not_saved' => 'No he cambiado tu plan: el plan no se guardó, y no se guardó nada de ningún día. Pídemelo de nuevo con ":save_plan".',
        'detail_days' => ' (:days: :detail)',
        'detail_only' => ' (:detail)',
    ],

    'week_detail' => [
        'heading_not_that_day' => 'la fecha de ese encabezado no corresponde a ese día de la semana',
        'twice' => 'ese día aparece dos veces',
        'next_week_no_dates' => 'el plan es para la próxima semana pero sus días no tienen fechas',
        'no_such_day' => 'el plan no tiene ese día',
        'too_far' => 'eso queda a más de dos meses',
        'apart' => 'sus días están separados por más de una semana',
        'cut_off' => 'parece cortado o incompleto',
        'alternatives' => 'ofrece alternativas (opciones) y solo puedo guardar un entrenamiento por día',
        'note_mentions_exercises' => 'el texto bajo su nota menciona ejercicios que dejaría fuera',
        'activity_no_numbers' => 'menciona una actividad sin series, repeticiones ni tiempo',
        'rest_or_else' => 'dice descanso u otra cosa',
        'no_exercises_no_rest' => 'no enumera ejercicios y no dice que sea un día de descanso',
        'rest_but_exercises' => 'se llama día de descanso pero también enumera ejercicios',
        'stray_day_line' => 'una línea debajo nombra otro día sin ser un título propio',
        'unknown' => 'no pude leerlo con seguridad',
        'line_without_numbers' => 'la línea ":line" no indica series, repeticiones ni tiempo, así que tendría que dejarla fuera',
    ],

    'reason' => [
        'plan_appeared' => 'apareció un plan el :date después de que te pregunté.',
        'plan_gone' => 'el plan por el que te pregunté para el :date ya no está.',
        'plan_changed' => 'el plan del :date cambió después de que te pregunté.',
        'performed' => 'el :date ya tiene un entrenamiento realizado y registrado, así que su plan ya no se puede reemplazar.',
        'not_saved' => 'el :date no se guardó.',
        'not_saved_rest' => 'el :date no se guardó como día de descanso.',
        'not_as_shown' => 'el :date no contiene lo que se mostró.',
        'went_wrong' => 'algo salió mal al guardar, así que no se guardó ningún día.',
        'above_limit' => 'El peso planificado de :load kg para ":exercise" supera el cambio máximo permitido: la carga más alta registrada para ese ejercicio en los últimos :days días es de :recorded kg, y un cambio del plan puede aumentarla como máximo un :percent% (:maximum kg). Ese límite solo acota el tamaño de un cambio; no significa que una carga hasta ese valor sea adecuada.',
        'above_baseline' => 'El peso planificado de :load kg para ":exercise" el :date superaría los :baseline kg que realizaste por última vez o que ya tienes planificados para ese día. Las cargas solo suben cuando apruebas una sugerencia calculada o pides el cambio de forma explícita, así que no se cambió nada.',
        'unknown' => 'no se pudo completar el cambio.',
    ],

    'range' => [
        'part' => ':exercise :said',
        'said' => ':amount :unit (escrito :low-:high)',
        'sets' => 'series',
        'reps' => 'repeticiones',
        'seconds' => 'segundos',
    ],
];
