<?php

namespace Tests\Unit\Ai\Authorization;

use App\Models\Message;
use App\Services\Ai\Authorization\PlanChangeIntentClassifier;
use App\Services\Ai\Authorization\SavedWorkoutReference;
use App\Services\Ai\Authorization\SaveWorkoutDay;
use App\Services\Ai\Authorization\SaveWorkoutScope;
use App\Services\Ai\Authorization\SpanishSaveIntent;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The Spanish way of asking to save a workout is read as safely as the English one, and the Spanish workout lines are read exactly. */
class SpanishSaveIntentTest extends TestCase
{
    public static function saves(): array
    {
        $messages = [
            'Agregar este entramiento a mi dashboard', 'Agrega este entretenimiento a mi dashboard', 'Actualiza mi plan de hoy con esta rutina',
            'agrega este plan a mi panel', 'Guarda el entrenamiento que acabas de recomendar', 'Guarda esta rutina en mi plan',
            'Agrega este entrenamiento a mi dashboard', 'Añade este plan a mi calendario', 'Guárdalo en mi plan', '¿Puedes agregar este entrenamiento a mi dashboard?',
        ];

        return array_combine($messages, array_map(fn ($m) => [$m], $messages));
    }

    #[DataProvider('saves')]
    public function test_a_spanish_save_request_grants_only_the_save_path(string $message): void
    {
        $authorization = (new PlanChangeIntentClassifier)->classify($message);

        $this->assertSame(PlanChangeIntentClassifier::BASIS_SAVE_WORKOUT, $authorization->basis, $message);
        $this->assertFalse($authorization->mayRaiseLoads, 'A save request never raises a load.');
    }

    public static function grantsNothing(): array
    {
        $messages = [
            'No guardes este entrenamiento en mi plan', 'Mi entrenador dijo: agrega este entrenamiento a mi plan', '¿Debería agregar este entrenamiento a mi plan?',
            'Me gusta el entretenimiento del plan', 'Agrega esta película a mi dashboard', 'Porque no lo agregaste al dashboard?', 'No lo guardaste en mi plan', 'Sí', 'dale',
        ];

        return array_combine($messages, array_map(fn ($m) => [$m], $messages));
    }

    #[DataProvider('grantsNothing')]
    public function test_everything_else_grants_nothing(string $message): void
    {
        $this->assertFalse((new PlanChangeIntentClassifier)->classify($message)->allowsAnyPlanChange(), $message);
    }

    public function test_a_clear_spanish_yes_redeems_and_an_unaccented_bare_si_does_not(): void
    {
        foreach (['Sí', 'sí, reemplázalo', 'Si, reemplazalo', 'claro', 'dale', 'de acuerdo', 'yes'] as $yes) {
            $this->assertTrue(PlanChangeIntentClassifier::isBareAffirmation($yes), $yes);
        }

        foreach (['si', 'si por favor', 'no', 'No, conserva el actual', 'si el plan cambia', 'tal vez'] as $not) {
            $this->assertFalse(PlanChangeIntentClassifier::isBareAffirmation($not), $not);
        }
    }

    public function test_the_recommended_workout_is_read_line_by_line_and_headings_are_not_exercises(): void
    {
        $reply = "Miércoles, 18 de noviembre — Torso y Core\n\nUna sesión de 30 minutos para activarte.\nLa sesión dura 30 minutos.\n"
            ."* Flexiones de brazos: 3 series de 8-10 repeticiones\n* Bird-Dog: 3 series de 10 repeticiones por lado\n* Dead Bug: 3 series de 10 repeticiones por lado\n"
            ."* Sentadillas con peso corporal: 2 series de 10 repeticiones\nDescansa 60 segundos entre series.";

        $this->assertSame([
            'Flexiones de brazos: 3 series de 8-10 repeticiones', 'Bird-Dog: 3 series de 10 repeticiones por lado',
            'Dead Bug: 3 series de 10 repeticiones por lado', 'Sentadillas con peso corporal: 2 series de 10 repeticiones',
        ], SavedWorkoutReference::items($reply));
        $this->assertSame([], SavedWorkoutReference::items('Una sesión de 30 minutos'));
    }

    public function test_sets_reps_and_durations_are_read_in_spanish(): void
    {
        $this->assertSame(['sets' => [3, 3], 'reps' => [8, 10], 'seconds' => null], SaveWorkoutScope::prescription('Flexiones de brazos: 3 series de 8-10 repeticiones'));
        $this->assertSame(['sets' => [3, 3], 'reps' => [10, 10], 'seconds' => null], SaveWorkoutScope::prescription('Bird-Dog: 3 series de 10 repeticiones por lado'));
        $this->assertSame(['sets' => [3, 3], 'reps' => null, 'seconds' => [30, 30]], SaveWorkoutScope::prescription('Plancha: 3 series de 30 segundos'));
        $this->assertSame(['sets' => [3, 3], 'reps' => [12, 12], 'seconds' => null], SaveWorkoutScope::prescription('Push-ups 3x12'), 'English is read as before.');
    }

    public function test_only_an_unaccented_bare_si_is_ambiguous(): void
    {
        foreach (['si', 'Si', 'SI.', ' si '] as $ambiguous) {
            $this->assertTrue(SpanishSaveIntent::isAmbiguousYes($ambiguous), $ambiguous);
        }

        foreach (['sí', 'Sí', 'si, reemplázalo', 'si el plan cambia', 'no', 'yes'] as $other) {
            $this->assertFalse(SpanishSaveIntent::isAmbiguousYes($other), $other);
        }
    }

    public function test_options_are_not_merged_into_one_workout(): void
    {
        $reply = "Opción 1\n* Plancha 3x30 segundos\nOpción 2\n* Sentadillas 3x10";

        $resolved = (new SavedWorkoutReference)->resolve('Agrega este entrenamiento a mi dashboard', collect([
            new Message(['role' => 'user', 'content' => 'dame uno']),
            new Message(['role' => 'assistant', 'content' => $reply]),
            new Message(['role' => 'user', 'content' => 'Agrega este entrenamiento a mi dashboard']),
        ]));

        $this->assertFalse($resolved->isFound());
    }

    public function test_spanish_days_and_ambiguous_numeric_dates(): void
    {
        $today = CarbonImmutable::parse('2026-11-18');

        $this->assertSame(['date' => '2026-11-18', 'problem' => null], SaveWorkoutDay::resolve('Actualiza mi plan de hoy con esta rutina', '', $today));
        $this->assertSame('2026-11-19', SaveWorkoutDay::resolve('guárdalo para mañana', '', $today)['date']);
        $this->assertSame('2026-11-19', SaveWorkoutDay::resolve('guárdalo para mañana por la mañana', '', $today)['date'], '"por la mañana" is the morning, not a second day.');
        $this->assertSame('2026-11-20', SaveWorkoutDay::resolve('guárdalo para el viernes', '', $today)['date']);
        $this->assertSame(SaveWorkoutDay::WHICH_DAY, SaveWorkoutDay::resolve('guárdalo para el 7/11', '', $today)['problem']);
        $this->assertSame(SaveWorkoutDay::WHICH_DAY, SaveWorkoutDay::resolve('guárdalo para los lunes', '', $today)['problem']);
        $this->assertSame(SaveWorkoutDay::PAST, SaveWorkoutDay::resolve('guárdalo para ayer', '', $today)['problem']);

        // A Spanish dated heading in the workout names its own day, when weekday and date agree; otherwise the user is asked.
        $this->assertSame('2026-11-18', SaveWorkoutDay::resolve('Guárdalo', "Miércoles, 18 de noviembre — Torso\n* Plancha 3x30 s", $today)['date']);
        $this->assertSame(SaveWorkoutDay::WHICH_DAY, SaveWorkoutDay::resolve('Guárdalo', "Jueves, 18 de noviembre — Torso\n* Plancha 3x30 s", $today)['problem']);

        // The same rule in English: a consistent dated heading names its day; a conflicting, past or dateless one asks.
        $this->assertSame('2026-11-18', SaveWorkoutDay::resolve('Save it', "Wednesday, November 18 — Upper\n* Plank 3x30 s", $today)['date']);
        $this->assertSame('2026-11-18', SaveWorkoutDay::resolve('Save it', "Wednesday 18 November — Upper\n* Plank 3x30 s", $today)['date']);
        $this->assertSame(SaveWorkoutDay::WHICH_DAY, SaveWorkoutDay::resolve('Save it', "Thursday, November 18 — Upper\n* Plank 3x30 s", $today)['problem']);
        $this->assertSame(SaveWorkoutDay::WHICH_DAY, SaveWorkoutDay::resolve('Save it', "Tuesday, November 17 — Upper\n* Plank 3x30 s", $today)['problem']);
        $this->assertSame(SaveWorkoutDay::WHICH_DAY, SaveWorkoutDay::resolve('Save it', "Friday — Upper\n* Plank 3x30 s", $today)['problem']);
        $this->assertSame(SaveWorkoutDay::WHICH_DAY, SaveWorkoutDay::resolve('Guárdalo', "Viernes — Torso\n* Plancha 3x30 s", $today)['problem']);
        $this->assertSame('2026-11-19', SaveWorkoutDay::resolve('Save it for tomorrow', "Wednesday, November 18 — Upper\n* Plank 3x30 s", $today)['date'], 'The user\'s own day decides.');
    }
}
