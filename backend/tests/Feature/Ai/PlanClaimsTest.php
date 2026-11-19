<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Notices\PlanClaims;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 7 / M3a: what the model claims about the user's plan is recognised in English and Spanish, whatever language
 * the user chose, and left alone when the sentence is a question, a negation, a hypothesis, a plan for later, something
 * from the past, or somebody's quoted words. A match only ever leads to a correction; it grants and writes nothing.
 */
class PlanClaimsTest extends TestCase
{
    // ---- "it was saved" -----------------------------------------------------------------------------------------------

    public static function falseSaveClaims(): array
    {
        return [
            // English: the original shapes
            ['en', "I've added it to your dashboard."],
            ['en', 'Done! I have saved the workout to your plan.'],
            ['en', 'It has been pushed to your calendar.'],
            ['en', "Great, I've just scheduled it on your schedule for Friday."],
            ['en', 'I updated your plan.'],
            // English: passive and "successfully"
            ['en', 'Your plan has been updated.'],
            ['en', 'The workout was saved.'],
            ['en', 'Your dashboard is now updated.'],
            ['en', 'The changes were applied successfully.'],
            ['en', 'Successfully saved your workout.'],
            ['en', 'Your weekly plan has been replaced with the new one.'],
            // Spanish
            ['es', 'He añadido el entrenamiento a tu plan.'],
            ['es', 'Ya lo guardé en tu dashboard.'],
            ['es', 'Listo, acabo de agregar la rutina a tu calendario.'],
            ['es', 'Actualicé tu semana con el nuevo plan.'],
            ['es', 'Tu plan ha sido actualizado.'],
            ['es', 'El entrenamiento quedó guardado en tu plan.'],
            ['es', 'Tu plan semanal fue reemplazado por el nuevo.'],
            ['es', 'Los cambios se aplicaron correctamente.'],
            ['es', 'Los cambios fueron aplicados.'],
            ['es', 'Entrenamiento guardado con éxito.'],
            ['es', 'Quedó en tu plan para el viernes.'],
            ['es', "¡Perfecto! Lo agregué a tu panel.\nNos vemos mañana."],
        ];
    }

    #[DataProvider('falseSaveClaims')]
    public function test_a_claim_that_something_was_saved_is_recognised_in_the_language_it_is_made_in(string $language, string $text): void
    {
        $this->assertSame($language, PlanClaims::claimsPlanWritten($text), $text);
    }

    public static function notClaims(): array
    {
        return [
            // questions
            'en question' => ['Would you like me to add it to your dashboard?'],
            'en question about the past' => ['Have I added it to your plan?'],
            'es question' => ['¿Quieres que lo añada a tu plan?'],
            'es question with inverted mark only' => ['¿Ya guardé tu entrenamiento en el plan'],
            'es question about the past' => ['¿Ya guardé tu plan?'],
            // negations
            'en negation' => ["I haven't added it to your plan."],
            'en negation 2' => ['I have not saved anything to your dashboard.'],
            'es negation' => ['No he guardado nada en tu plan.'],
            'es negation 2' => ['Todavía no añadí el entrenamiento a tu plan.'],
            'es never' => ['Nunca he actualizado tu plan sin que me lo pidas.'],
            // hypotheticals, offers, the future
            'en offer' => ['I can add it to your plan if you want.'],
            'en future' => ['Once you confirm, I will save it to your plan.'],
            'en conditional' => ['If you say yes, the plan would be updated.'],
            'es offer' => ['Puedo añadirlo a tu plan si quieres.'],
            'es future' => ['Cuando confirmes, lo guardaré en tu plan.'],
            'es conditional' => ['Si dices que sí, tu plan sería actualizado.'],
            'es going to' => ['Voy a agregar la rutina a tu calendario.'],
            // quoted words
            'en quoted' => ['You can say "I\'ve added it to your plan" to a friend, but I have not.'],
            'en quoted curly' => ['The message “Your plan has been updated.” is what you would see after confirming.'],
            'es quoted' => ['El aviso «Ya lo guardé en tu plan» aparece después de confirmar.'],
            'es quoted double' => ['Verás el mensaje "Tu plan ha sido actualizado" cuando confirmes.'],
            'en blockquote' => ["Here is what you wrote:\n> I've added it to your plan\nBut nothing was done."],
            // the past
            'en yesterday' => ['Your plan was updated yesterday to include squats.'],
            'es yesterday' => ['Ayer guardé tu entrenamiento en el plan.'],
            'es last week' => ['Tu plan fue actualizado la semana pasada.'],
            // ordinary coaching text that mentions the same words
            'en plain plan' => ['Here is a plan for today: squats and rows.'],
            'en describes plan' => ['Your plan includes three strength days and two rest days.'],
            'es plain plan' => ['Aquí tienes un plan de entrenamiento para hoy.'],
            'es describes plan' => ['Tu plan incluye tres días de fuerza y dos de descanso.'],
            'es coach opinion' => ['Mi entrenador cree que tu plan es muy bueno.'],
            'es word cree is not creé' => ['Se cree que añadir descanso al plan ayuda.'],
            'empty' => [''],
        ];
    }

    #[DataProvider('notClaims')]
    public function test_a_question_a_negation_a_hypothesis_a_quote_or_the_past_is_not_a_claim(string $text): void
    {
        $this->assertNull(PlanClaims::claimsPlanWritten($text), $text);
    }

    public function test_the_original_english_claim_pattern_is_not_softened_by_a_time_word(): void
    {
        // "I've added it ... earlier" is exactly what a false claim can look like, so the original pattern keeps matching it.
        // (A wrongly corrected true statement costs a disclaimer; a missed false one costs the user's trust.)
        $this->assertSame('en', PlanClaims::claimsPlanWritten("I've added a rest day to your plan earlier this month."));
    }

    public function test_the_claim_is_found_in_whichever_sentence_makes_it_and_both_languages_are_always_tried(): void
    {
        $this->assertSame('es', PlanClaims::claimsPlanWritten('Great job today! Ya lo guardé en tu plan. See you tomorrow.'));
        $this->assertSame('en', PlanClaims::claimsPlanWritten('¡Gran trabajo! I have saved the workout to your plan.'));
        $this->assertSame('en', PlanClaims::claimsPlanWritten("Would you like that?\nI've added it to your dashboard."));
    }

    // ---- "I have no access" -------------------------------------------------------------------------------------------

    public static function falseAccessClaims(): array
    {
        return [
            ['en', "I'm sorry, but I don't have access to the plan updating tool right now, so I can't add these to your dashboard."],
            ['en', 'I am unable to update or push a workout plan directly to your Dashboard.'],
            ['en', 'The plan tool is not available at the moment.'],
            ['en', 'I cannot modify your schedule.'],
            ['es', 'Lo siento, no tengo acceso a la herramienta para actualizar tu plan.'],
            ['es', 'No puedo actualizar tu plan directamente.'],
            ['es', 'No puedo añadir entrenamientos a tu dashboard.'],
            ['es', 'La herramienta del plan no está disponible en este momento.'],
            ['es', 'No me es posible guardar cambios en tu calendario.'],
            ['es', 'No estoy en capacidad de modificar tu plan.'],
        ];
    }

    #[DataProvider('falseAccessClaims')]
    public function test_a_claim_of_having_no_access_is_recognised_in_either_language(string $language, string $text): void
    {
        $this->assertSame($language, PlanClaims::claimsNoAccess($text), $text);
    }

    public static function notAccessClaims(): array
    {
        return [
            'en safety' => ["I can't tell whether that is safe for your plan; please ask your doctor."],
            'en recommend' => ['I would not recommend that you update your plan while injured.'],
            'en unrelated' => ['The gym is unavailable on Sunday.'],
            'en quoted' => ['The message "I don\'t have access to the plan tool" would be wrong.'],
            'es safety' => ['No puedo decirte si es seguro cambiar tu plan con una lesión; consulta a tu médico.'],
            'es recommend' => ['No te recomiendo actualizar tu plan mientras estás lesionado.'],
            'es unrelated' => ['No puedo ver tu pulso en tiempo real.'],
            'es quoted' => ['El mensaje «No tengo acceso a la herramienta del plan» sería incorrecto.'],
            'es plain' => ['Hoy toca descanso activo: caminata de 30 minutos.'],
            'empty' => [''],
        ];
    }

    #[DataProvider('notAccessClaims')]
    public function test_safety_advice_unrelated_statements_and_quotes_are_not_access_claims(string $text): void
    {
        $this->assertNull(PlanClaims::claimsNoAccess($text), $text);
    }
}
