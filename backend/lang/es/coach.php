<?php

/*
 * Lo que la aplicación dice por sí misma debajo de una respuesta del entrenador, o en su lugar (Phase 7 / M3b).
 *
 * Español latinoamericano neutro, con "tú". Los marcadores (":nombre") coinciden con lang/en/coach.php.
 * Las frases que el usuario debe ESCRIBIR (":save_workout", ":yes", ...) vienen de EnglishCommands: los analizadores
 * de solicitudes solo entienden inglés hasta M4, por eso "" lo dice claramente.
 *
 * Nada de esto se vuelve a leer para decidir algo: es solo texto de presentación.
 */

return [
    // Vacío desde Phase 7 / M4: los analizadores leen español, así que ya no hay nada que avisar. (La clave se conserva igual que en inglés.)
    'english_note' => '',

    'earlier_day' => 'un día anterior',

    'save_question' => [
        'no_workout' => 'No he cambiado tu plan: no veo un entrenamiento en mi última respuesta ni en tu mensaje para añadir. Pídeme uno (por ejemplo, ":ask_workout") o dime qué añadir, como ":example". También puedes responder solo con los ejercicios y sus series y repeticiones.',
        'which_workout' => 'No he cambiado tu plan: podrías referirte a más de un entrenamiento y no quiero adivinar cuál. Dime cuál (por ejemplo, ":option_command", o solo ":option"), o envía los ejercicios en un solo mensaje, como ":example".',
        'incomplete' => 'No he cambiado tu plan: ese entrenamiento parece incompleto (está cortado, se queda a medias o enumera menos ejercicios de los que anuncia). Envíame la lista completa, como ":example", o pídeme que lo escriba completo primero.',
        'quoted' => 'No he cambiado tu plan: ese entrenamiento es una cita de palabras de otra persona y quiero asegurarme de que es el que quieres. Escribe los ejercicios en tu propio mensaje, como ":example".',
        'retry_new_day' => 'No he cambiado tu plan: esa solicitud para añadir un entrenamiento se hizo el :made_on y ya empezó un nuevo día, así que no la retomo para no adivinar qué día quieres ahora. Para añadir el entrenamiento, escribe ":save_workout" (y el día, si quieres uno distinto de hoy).',
        'retry_too_old' => 'No he cambiado tu plan: esa solicitud para añadir un entrenamiento tiene más de :minutes minutos, y solo retomo una solicitud mientras es reciente, así que no se guardó nada. Para añadir el entrenamiento, escribe ":save_workout" (y el día, si quieres uno distinto de hoy).',
        'which_day_past' => 'No he cambiado tu plan: no tengo claro qué día quieres, porque solo puedo añadir un entrenamiento a hoy o a un día posterior (hasta dos meses adelante). ¿Qué día debo usar? Responde solo con el día, por ejemplo ":friday" o ":tomorrow".',
        'which_day' => 'No he cambiado tu plan: no tengo claro qué día quieres. Puedo añadir un entrenamiento a hoy o a un día de los próximos dos meses. ¿Qué día debo usar? Responde solo con el día, por ejemplo ":friday" o ":tomorrow".',
        'choice_one' => 'No he cambiado tu plan, y no hay ningún problema para guardarlo: una cosa del entrenamiento es una elección. Dice :lines. ¿Cuál debo añadir? Responde solo con el nombre, por ejemplo ":first".',
        'choice_many' => 'No he cambiado tu plan, y no hay ningún problema para guardarlo: una cosa del entrenamiento es una elección. Dice :lines. ¿Cuál debo añadir? Responde solo con los nombres, por ejemplo ":first".',
        'choice_or' => 'o',
        'choice_first' => 'la primera opción',
    ],

    'week_question' => [
        'unclear_day' => 'No he cambiado tu plan: no puedo saber exactamente qué guardar para :days (:detail), y no quiero adivinar ni inventar un entrenamiento. :nothing_saved Dime qué incluye ese día (por ejemplo, ":sunday_rest", o enumera sus ejercicios con series y repeticiones), o pídeme que escriba el plan completo y luego escribe ":save_plan" de nuevo.',
        'past_days' => 'No he cambiado tu plan: este plan incluye días que ya pasaron (:days), y solo puedo añadir hoy o un día posterior. :nothing_saved Responde solo con los días que quieres añadir, por ejemplo ":saturday_sunday".',
        'no_such_day' => 'No he cambiado tu plan: ese plan no tiene :days. :nothing_saved Responde solo con los días del plan que quieres añadir, por ejemplo ":saturday_sunday".',
        'dates' => 'No he cambiado tu plan: no puedo saber para qué fechas es este plan:detail. :nothing_saved Pídeme que escriba el plan con una fecha en cada día (por ejemplo, ":dated_day") y luego escribe ":save_plan" de nuevo.',
        'too_many' => 'No he cambiado tu plan: ese plan tiene más de :max días, más de los que puedo añadir de una vez. :nothing_saved Pídeme que lo añada una semana a la vez.',
        'unreadable' => 'No he cambiado tu plan: no pude leer ese plan día por día. :nothing_saved Pídeme que lo escriba con los ejercicios de cada día y luego escribe ":save_plan".',
    ],

    'save' => [
        'failed' => 'No he cambiado tu plan: el entrenamiento no se guardó. Pídemelo de nuevo con ":save_workout", o envía los ejercicios en un solo mensaje.',
        'day_performed' => 'No he cambiado tu plan: el :date ya tiene un entrenamiento realizado y registrado, así que su plan no se puede reemplazar. Elige otro día, como ":save_workout_tomorrow".',
        'why_not_saved' => 'No se ha guardado ningún entrenamiento en tu plan en esta conversación: la aplicación no registró ninguna escritura, y no voy a decirte que se guardó. Para guardar el entrenamiento que te acabo de mostrar, escribe ":save_workout" (y el día, si quieres uno distinto de hoy). Si algo lo impide (un entrenamiento que no puedo leer completo, un día que ya tiene uno o un día poco claro), te diré exactamente qué es.',
        'why_not_saved_last' => 'Esto es lo último que pasó, con las palabras de la aplicación: :notice',
        'replaced_as_asked' => 'Como pediste, reemplacé :was el :date por este entrenamiento.',
        'cannot_ask_confirmation' => 'No he cambiado tu plan: eso reemplazaría un día que ya tiene un plan y no pude pedirte que lo confirmes en este momento. Inténtalo de nuevo.',
    ],

    'confirmation' => [
        'unclear' => 'No he cambiado tu plan: no estoy seguro de que eso fuera un sí. Para confirmar exactamente lo que te mostré, responde ":yes" (con tilde); para dejar tu plan como está, responde "no". La pregunta sigue abierta.',
        'invalid' => 'No he cambiado tu plan: esa confirmación ya no es válida (venció, ya se usó o no corresponde a esta conversación). Si aún quieres el entrenamiento en tu plan, pídemelo de nuevo.',
        'refused' => 'No lo reemplacé: :reason No se cambió nada. Si aún quieres el entrenamiento en tu plan, pídemelo de nuevo.',
        'done' => 'Listo.',
        'checked' => 'Comprobé que quedó guardado.',
        'added' => 'Añadí :replacement el :date.',
        'marked_rest' => 'Marqué el :date como día de descanso.',
        'turned_rest' => 'Convertí el :date (:existing) en día de descanso.',
        'replaced' => 'Reemplacé :existing el :date por :replacement.',
    ],

    'follow_up' => [
        'explicit' => 'Para cambiar tu plan, envía una solicitud explícita que nombre el ejercicio, la carga en kg y el día, por ejemplo ":update_example".',
        'proposal_no_longer_valid' => 'Tu plan cambió desde que te pregunté, así que no hice ese cambio. :explicit',
        'nothing_pending' => 'No hay nada esperando tu confirmación (puede que haya vencido).',
        'nothing_pending_discussed' => 'Para poner en tu plan el entrenamiento que acabamos de comentar, escribe ":save_workout".',
        'explicit_request_needed' => 'No he cambiado tu plan. :explicit',
        'confirmed_change_failed' => 'No pude hacer el cambio exactamente como lo confirmaste, así que no se cambió nada. Si aún lo quieres, pídelo de nuevo.',
        'change_proposed' => 'No he cambiado tu plan. ¿Quieres que :describe? Responde ":yes" en los próximos :minutes minutos para confirmar exactamente eso y nada más. El límite de cuánto puede aumentar un cambio solo acota su tamaño; no significa que la carga sea adecuada para ti.',
        'above_maximum_change' => 'No he cambiado tu plan, y no puedo ofrecer :requested kg: un cambio puede aumentar como máximo un 10% tu carga registrada más pesada (:recorded kg), es decir, :maximum kg. Eso es un máximo, no una recomendación, y no implica que cualquier carga hasta ese valor sea adecuada para ti.',
        'change_not_specific' => 'No he cambiado tu plan, y no pude convertir eso en un cambio específico para confirmar. :explicit',
    ],

    'correction' => [
        'no_access_intro' => 'Para ser claro: eso no es un límite de la aplicación, puedo añadir y cambiar entrenamientos en tu plan cuando me lo pidas.',
        'nothing_changed' => 'Esta vez no he cambiado nada.',
        'no_access_how' => 'Para añadir un entrenamiento que acabo de mostrarte, escribe ":save_workout" (o ":try_again" si ya lo habías pedido).',
        'not_saved' => 'No he cambiado tu plan: esta vez no se guardó nada, diga lo que diga el mensaje de arriba. Para añadir un entrenamiento que acabo de mostrarte, escribe ":save_workout".',
        'partial' => 'Ojo: no todo lo que pediste se cambió. Al menos un cambio en tu plan fue rechazado, omitido o no se pudo completar, así que revisa tu plan antes de dar por hecho lo que dice el mensaje de arriba.',
    ],

    'load_left_unset' => 'Dejé sin definir el peso inicial de :names: no hay un peso registrado o planificado en el que basarlo. Elige un peso que puedas levantar con comodidad para las repeticiones objetivo y regístralo cuando anotes tus series.',
    'range_note' => 'Tu plan guarda un solo número para cada uno de estos, así que guardé el extremo inferior de cada rango: :parts. Puedes cambiarlos en el plan.',

    'weekly' => [
        'no_change' => 'No hace falta ningún cambio.',
        'not_determined' => 'No pude determinar un cambio en el plan a partir de eso; intenta reformularlo.',
    ],
    'dashboard_fallback' => 'Registra tu actividad de hoy y prepararé una recomendación para ti.',
];
