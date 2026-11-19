<?php

namespace Tests\Unit\Ai\Authorization;

use App\Services\Ai\Authorization\Confirmation;
use App\Services\Ai\Authorization\PlanChangeIntentClassifier;
use App\Services\Ai\Authorization\SaveRetry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 / M4 (group 1): a Spanish request is read exactly as safely as its English equivalent.
 *
 * Language is a way of saying something, never a permission: the same intent has to give the same decision in either
 * language, and what grants nothing in English grants nothing in Spanish.
 */
class SpanishIntentTest extends TestCase
{
    private function allows(string $message): bool
    {
        return (new PlanChangeIntentClassifier)->classify($message)->allowsAnyPlanChange();
    }

    /** @return array<string, array{string, string}> */
    public static function equivalentRequests(): array
    {
        return [
            'save for tomorrow' => ['Save this workout for tomorrow.', 'Guarda este entrenamiento para mañana.'],
            'add routine' => ['Add this routine to my plan.', 'Agrega esta rutina a mi plan.'],
            'add plan to calendar' => ['Add this plan to my calendar.', 'Añade este plan a mi calendario.'],
            'change friday' => ['Change my Friday workout.', 'Cambia el entrenamiento del viernes.'],
            'replace tomorrow' => ["Replace tomorrow's workout.", 'Reemplaza el entrenamiento de mañana.'],
            'update plan' => ['Update my plan.', 'Actualiza mi plan.'],
        ];
    }

    #[DataProvider('equivalentRequests')]
    public function test_a_request_is_decided_the_same_in_either_language(string $english, string $spanish): void
    {
        $this->assertTrue($this->allows($english), $english);
        $this->assertSame($this->allows($english), $this->allows($spanish), $spanish);
        $this->assertSame($this->allows($english), $this->allows(strtoupper($spanish)), 'Case does not matter.');
        $this->assertSame($this->allows($english), $this->allows(str_replace(['ñ', 'á', 'é', 'í', 'ó', 'ú'], ['n', 'a', 'e', 'i', 'o', 'u'], $spanish)), 'A missing accent does not matter.');
    }

    /** @return array<string, array{string}> */
    public static function grantsNothing(): array
    {
        $messages = [
            'No quiero que cambies mi plan.',
            'No guardes este entrenamiento.',
            '¿Debería cambiar mi entrenamiento del viernes?',
            '¿Puedes actualizar mi plan?' => null,
            'Mi entrenador dijo "reemplaza el entrenamiento de mañana" pero no lo hagas.',
            'Mi entrenador me dijo que cambie mi plan.',
            'Sí.',
            'Dale.',
            'Quiero subir a 60 kg en press de banca.',
            '¿Cómo me fue en sentadillas la última vez?',
        ];
        unset($messages['¿Puedes actualizar mi plan?']);

        return array_combine($messages, array_map(fn ($m) => [$m], $messages));
    }

    #[DataProvider('grantsNothing')]
    public function test_spanish_that_is_a_question_a_refusal_a_quote_or_someone_elses_instruction_grants_nothing(string $message): void
    {
        $this->assertFalse($this->allows($message), $message);
    }

    public function test_a_spanish_refusal_and_a_bare_affirmation_are_told_apart(): void
    {
        $this->assertTrue(PlanChangeIntentClassifier::isBareAffirmation('Sí, reemplázalo.'));
        $this->assertTrue(PlanChangeIntentClassifier::isRefusal('No, conserva el actual.'));
        $this->assertFalse(PlanChangeIntentClassifier::isBareAffirmation('No, conserva el actual.'));
        $this->assertFalse(PlanChangeIntentClassifier::isRefusal('Sí, reemplázalo.'));
    }

    /** @return array<string, array{string, string}> */
    public static function confirmations(): array
    {
        return [
            'accented yes' => ['Sí', Confirmation::YES],
            'yes with a verb' => ['Sí, reemplázalo.', Confirmation::YES],
            'unaccented yes with a verb' => ['si, reemplazalo', Confirmation::YES],
            'claro' => ['Claro', Confirmation::YES],
            'dale' => ['Dale', Confirmation::YES],
            'de acuerdo' => ['De acuerdo', Confirmation::YES],
            'english yes' => ['yes', Confirmation::YES],
            'bare unaccented si' => ['si', Confirmation::UNCLEAR],
            'maybe' => ['tal vez', Confirmation::UNCLEAR],
            'depends' => ['depende', Confirmation::UNCLEAR],
            'not sure' => ['no estoy seguro', Confirmation::UNCLEAR],
            'refusal' => ['No', Confirmation::NO],
            'refusal with an explanation' => ['No, conserva el actual.', Confirmation::NO],
            'english no' => ['no thanks', Confirmation::NO],
            'a new request' => ['Guarda los entrenamientos del lunes y miércoles.', Confirmation::NONE],
            'a question' => ['¿Sí o no?', Confirmation::NONE],
        ];
    }

    #[DataProvider('confirmations')]
    public function test_a_short_answer_is_read_as_yes_no_or_unclear_and_never_more_generously(string $answer, string $expected): void
    {
        $this->assertSame($expected, Confirmation::classify($answer), $answer);
    }

    public function test_an_answer_that_is_neither_a_clear_yes_nor_a_clear_no_never_redeems_anything(): void
    {
        foreach (['Déjalo como está', 'mmm', 'si quieres', 'si pero cámbialo', 'quizás mañana'] as $answer) {
            $this->assertFalse(Confirmation::isYes($answer), $answer);
        }
    }

    public function test_the_past_tense_and_the_hypothetical_are_decided_as_their_english_twins_are(): void
    {
        // Parity is the contract: the Spanish reader may not be more generous than the English one.
        foreach ([
            ['I changed my workout yesterday and it went well.', 'Ayer cambié mi entrenamiento y me fue bien.'],
            ['I would like to know what would happen if I change my workout.', 'Me gustaría saber qué pasaría si cambio el entrenamiento.'],
        ] as [$english, $spanish]) {
            $this->assertSame($this->allows($english), $this->allows($spanish), $spanish);
        }
    }

    public function test_english_hedges_keep_their_old_meaning(): void
    {
        // Spanish hedges ask for clarification; English "maybe" never did and must not start to.
        $this->assertNotSame(Confirmation::UNCLEAR, Confirmation::classify('maybe'));
        $this->assertFalse(Confirmation::isYes('maybe'));
    }

    public function test_a_spanish_retry_phrase_is_a_retry_and_an_unrelated_sentence_is_not(): void
    {
        $this->assertTrue(SaveRetry::isRetryPhrase('Inténtalo de nuevo.'));
        $this->assertTrue(SaveRetry::isRetryPhrase('Try again.'));
        $this->assertFalse(SaveRetry::isRetryPhrase('Quiero cenar temprano.'));
    }
}
