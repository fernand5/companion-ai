<?php

namespace Tests\Unit\Ai\Authorization;

use App\Models\Message;
use App\Services\Ai\Authorization\SavedWorkoutReference;
use App\Services\Ai\Authorization\WeekPlan;
use App\Services\Ai\Authorization\WeekPlanReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** M4: a plan of several days written in Spanish (or in both languages) is read as exactly as one written in English, or asked about. */
class SpanishWeekPlanReferenceTest extends TestCase
{
    /** A Thursday. The week is Mon 26 Oct - Sun 1 Nov 2026. */
    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-29', 'America/Bogota');
    }

    private function resolve(string $reply, string $user = 'Agrega este plan a mi panel'): WeekPlan
    {
        $history = new Collection([
            new Message(['role' => 'user', 'content' => 'crea un plan para el resto de la semana']),
            new Message(['role' => 'assistant', 'content' => $reply]),
            new Message(['role' => 'user', 'content' => $user]),
        ]);

        return (new WeekPlanReference)->resolve($user, $history, $this->today());
    }

    private const PLAN = "Aquí tienes un plan para el resto de la semana:\n\n**Sábado: Cuerpo completo**\n* Sentadillas goblet: 3 series de 10-12 repeticiones\n* Flexiones: 3 series de 8-12 repeticiones\n* Plancha: 3 series de 30 segundos\n\n**Domingo: Recuperación activa**\n* Caminata suave: 30 minutos\n* Estiramientos ligeros: 10 minutos\n\nSi este plan te parece bien, di \"agrega este plan a mi panel\" y lo actualizo para el sábado y el domingo.";

    public function test_a_spanish_plan_is_its_days_with_their_exercises(): void
    {
        $plan = $this->resolve(self::PLAN);

        $this->assertTrue($plan->isFound(), $plan->status.' '.$plan->detail);
        $this->assertSame(['2026-10-31', '2026-11-01'], array_column($plan->days, 'date'));
        $this->assertSame([WeekPlan::KIND_WORKOUT, WeekPlan::KIND_WORKOUT], array_column($plan->days, 'kind'));
        $this->assertCount(3, $plan->days[0]['items']);
        $this->assertCount(2, $plan->days[1]['items']);
        $this->assertSame('Sábado: Cuerpo completo', $plan->days[0]['label'], 'The heading as the coach wrote it, accents and all.');
    }

    public function test_rest_days_in_the_common_spanish_forms(): void
    {
        foreach (['**Domingo – Día de descanso**', '**Domingo: Descanso**', '**Domingo — Día libre**', '**Domingo: Sin entrenar**'] as $heading) {
            $plan = $this->resolve("Plan:\n\n**Sábado – Piernas**\n1. Sentadillas 3x10\n2. Plancha 3 x 30 segundos\n\n{$heading}\nRecupérate y descansa bien.\n\nDi \"agrega este plan a mi panel\".");

            $this->assertTrue($plan->isFound(), $heading.' '.$plan->status.' '.$plan->detail);
            $this->assertSame([WeekPlan::KIND_WORKOUT, WeekPlan::KIND_REST], array_column($plan->days, 'kind'), $heading);
            $this->assertSame([], $plan->days[1]['items']);
        }
    }

    public function test_a_rest_day_that_lists_work_or_an_empty_unlabelled_day_is_asked_about(): void
    {
        $both = $this->resolve("**Sábado – Descanso**\n* Plancha: 3 series de 30 segundos\n\n**Domingo – Caminata**\n* Caminar 30 minutos");
        $this->assertSame(WeekPlan::UNCLEAR_DAY, $both->status, 'Called a rest day but lists exercises.');

        $empty = $this->resolve("**Sábado – Piernas**\n* Sentadillas: 3 series de 10 repeticiones\n\n**Domingo**\nNos vemos.");
        $this->assertSame(WeekPlan::UNCLEAR_DAY, $empty->status, 'No exercises and does not say it is a rest day.');

        $either = $this->resolve("**Sábado – Piernas**\n* Sentadillas: 3 series de 10 repeticiones\n\n**Domingo**\nDescanso o caminata suave.");
        $this->assertSame(WeekPlan::UNCLEAR_DAY, $either->status, 'Rest or something else.');
    }

    public static function datedHeadings(): array
    {
        return [
            'day de month' => ["**Sábado, 31 de octubre: Piernas**\nSentadillas 3x10\n\n**Domingo, 1 de noviembre: Caminata**\nCaminata suave 30 minutos"],
            'abbreviated month' => ["**Sáb 31 oct – Piernas**\nSentadillas 3x10\n\n**Dom 1 nov – Caminata**\nCaminata suave 30 minutos"],
            'iso date' => ["**Sábado (2026-10-31): Piernas**\nSentadillas 3x10\n\n**Domingo (2026-11-01): Caminata**\nCaminata suave 30 minutos"],
            'numeric date the weekday settles' => ["**Sábado 31/10 – Piernas**\nSentadillas 3x10\n\n**Domingo 1/11 – Caminata**\nCaminata suave 30 minutos"],
            'day prefix' => ["Día 1 (Sábado): Piernas\nSentadillas 3x10\n\nDía 2 (Domingo): Caminata\nCaminata suave 30 minutos"],
            'de + topic' => ["**Sábado de piernas**\nSentadillas 3x10\n\n**Domingo de cardio**\nCaminata suave 30 minutos"],
        ];
    }

    #[DataProvider('datedHeadings')]
    public function test_spanish_dated_headings_resolve_to_the_right_days(string $reply): void
    {
        $plan = $this->resolve($reply);

        $this->assertTrue($plan->isFound(), $plan->status.' '.$plan->detail);
        $this->assertSame(['2026-10-31', '2026-11-01'], array_column($plan->days, 'date'));
    }

    public function test_a_date_that_does_not_fit_its_weekday_or_a_numeric_date_with_no_settling_weekday_is_asked_about(): void
    {
        $wrong = $this->resolve("**Sábado, 1 de noviembre: Piernas**\nSentadillas 3x10\n\n**Domingo, 2 de noviembre: Caminata**\nCaminata 30 minutos");
        $this->assertSame(WeekPlan::DATES, $wrong->status);

        // 7/11 is Saturday November 7th or Sunday July 11th: neither is a Saturday-the-31st; and neither reading fits "Sábado" -> never guessed.
        $ambiguous = $this->resolve("**Lunes 7/11 – Piernas**\nSentadillas 3x10\n\n**Martes 8/11 – Cardio**\nCaminata 30 minutos");
        $this->assertSame(WeekPlan::DATES, $ambiguous->status, 'No reading of 7/11 is a Monday this year: not guessed, not silently replaced by this week\'s Monday.');
    }

    public function test_a_line_that_names_other_days_without_being_a_heading_is_asked_about_not_read_around(): void
    {
        $plan = $this->resolve("**Sábado – Piernas**\nSentadillas 3x10\nDomingo y lunes: descanso\n\n**Domingo – Cardio**\nCaminata 30 minutos");
        $this->assertSame(WeekPlan::UNCLEAR_DAY, $plan->status);

        $range = $this->resolve("**Sábado – Piernas**\nSentadillas 3x10\n\nLunes a viernes: trabajo\n\n**Domingo – Cardio**\nCaminata 30 minutos");
        $this->assertSame(WeekPlan::UNCLEAR_DAY, $range->status);
    }

    public function test_a_spanish_alternative_is_asked_about_never_saved_together(): void
    {
        $plan = $this->resolve("**Sábado – Piernas**\nOpción A\n* Sentadillas 3x10\nOpción B\n* Zancadas 3x10\n\n**Domingo – Cardio**\nCaminata 30 minutos");
        $this->assertSame(WeekPlan::UNCLEAR_DAY, $plan->status);

        $or = $this->resolve("**Sábado – Piernas**\n* Sentadillas o zancadas: 3 series de 10 repeticiones\n\n**Domingo – Cardio**\n* Caminata 30 minutos");
        $this->assertSame(WeekPlan::CHOICE, $or->status, 'An exercise that is "A o B" is a choice to ask about.');
    }

    public function test_the_user_can_name_the_days_in_spanish(): void
    {
        $plan = $this->resolve(self::PLAN, 'Guarda el entrenamiento del sábado en mi plan');

        // One named day of a plan of two: only that day, the other said back as left out.
        $this->assertTrue($plan->isFound(), $plan->status.' '.$plan->detail);
        $this->assertSame(['2026-10-31'], array_column($plan->days, 'date'));
        $this->assertSame(['Domingo: Recuperación activa'], $plan->omitted);
    }

    public function test_the_next_week_without_dates_is_asked_about(): void
    {
        $plan = $this->resolve("Plan para la próxima semana:\n\n**Lunes – Piernas**\nSentadillas 3x10\n\n**Martes – Cardio**\nCaminata 30 minutos");

        $this->assertSame(WeekPlan::DATES, $plan->status);
    }

    public function test_a_plan_in_both_languages_is_read_day_by_day(): void
    {
        $plan = $this->resolve("**Saturday: Legs**\n* Squats: 3 sets of 10 reps\n\n**Domingo: Cardio**\n* Caminata suave: 30 minutos");

        $this->assertTrue($plan->isFound(), $plan->status.' '.$plan->detail);
        $this->assertSame(['2026-10-31', '2026-11-01'], array_column($plan->days, 'date'));
    }

    public function test_a_session_length_is_never_an_exercise_and_no_exercise_line_is_dropped(): void
    {
        $this->assertSame([], SavedWorkoutReference::items('Una sesión de 30 minutos'));
        $this->assertSame([], SavedWorkoutReference::items('Una sesion de 30 minutos'));
        $this->assertSame([], SavedWorkoutReference::items("Entrenamiento de hoy:\nUna sesión de 30 minutos para activarte.\nLa sesión dura 45 minutos."));

        // A day whose heading says "una sesión de 30 minutos" still lists every real line.
        $plan = $this->resolve("**Sábado – Una sesión de 30 minutos**\n* Sentadillas: 3 series de 10 repeticiones\n* Plancha: 3 series de 30 segundos\n\n**Domingo – Descanso**");
        $this->assertTrue($plan->isFound(), $plan->status.' '.$plan->detail);
        $this->assertCount(2, $plan->days[0]['items']);

        // A listed line with no amount would have been left out: asked about.
        $silent = $this->resolve("**Sábado – Piernas**\n* Sentadillas: 3 series de 10 repeticiones\n* Zancadas con mancuernas\n\n**Domingo – Descanso**");
        $this->assertSame(WeekPlan::UNCLEAR_DAY, $silent->status);
    }

    public function test_decimals_distances_and_per_side_instructions_are_read_whole(): void
    {
        $items = SavedWorkoutReference::items("* Correr 5,5 km\n* Caminar 2.5 km\n* Zancadas: 3 series de 10 repeticiones por lado\n* Plancha lateral: 3 series de 30 segundos por lado\n* Nadar 400 metros");

        $this->assertSame(['Correr 5,5 km', 'Caminar 2.5 km', 'Zancadas: 3 series de 10 repeticiones por lado', 'Plancha lateral: 3 series de 30 segundos por lado', 'Nadar 400 metros'], $items);

        // A decimal comma on one line of a list on one line is not a separator.
        $this->assertCount(2, SavedWorkoutReference::items('Correr 5,5 km, plancha 30 segundos'));
    }
}
