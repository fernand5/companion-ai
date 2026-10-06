<?php

namespace Tests\Feature\Fitness;

use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExerciseHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function perform(User $user, string $date, string $name, array $sets, string $recordedAs = 'entered', array $extra = []): WorkoutExercise
    {
        $session = WorkoutSession::factory()->for($user)->create(['logged_date' => $date]);
        $exercise = WorkoutExercise::factory()->for($session, 'workoutSession')->create([
            'exercise_name' => $name,
            'recorded_as' => $recordedAs,
        ] + $extra);

        foreach ($sets as $i => [$reps, $weight]) {
            WorkoutSet::factory()->for($exercise, 'exercise')->create(['set_number' => $i + 1, 'reps' => $reps, 'weight_kg' => $weight]);
        }

        return $exercise;
    }

    private function history(User $user, string $exercise, string $query = '')
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/exercises/'.rawurlencode($exercise).'/history'.$query);
    }

    public function test_history_lists_the_users_sessions_newest_first_with_every_set(): void
    {
        $user = User::factory()->create();
        $this->perform($user, '2026-09-20', 'Bench Press', [[10, 40], [10, 40], [10, 40]]);
        $this->perform($user, '2026-09-27', 'Bench Press', [[10, 42.5], [10, 42.5], [10, 42.5]]);
        $this->perform($user, '2026-10-04', 'Bench Press', [[8, 45], [8, 45], [7, 45]]);

        $response = $this->history($user, 'bench-press')->assertOk();

        $this->assertSame('bench-press', $response->json('exercise_slug'));
        $this->assertSame(['2026-10-04', '2026-09-27', '2026-09-20'], array_column($response->json('sessions'), 'date'));
        $this->assertSame([8, 8, 7], array_column($response->json('sessions.0.sets'), 'reps'));
        $this->assertEquals(45, $response->json('sessions.0.sets.0.weight_kg'));
        $this->assertEquals(42.5, $response->json('sessions.1.sets.0.weight_kg'));
    }

    public function test_a_name_and_its_slug_resolve_to_the_same_history(): void
    {
        $user = User::factory()->create();
        $this->perform($user, '2026-10-01', 'Goblet Squat', [[10, 20]]);
        $this->perform($user, '2026-10-03', '  goblet   SQUAT ', [[10, 22.5]]);

        $this->assertCount(2, $this->history($user, 'Goblet Squat')->json('sessions'));
        $this->assertCount(2, $this->history($user, 'goblet-squat')->json('sessions'));
    }

    public function test_the_limit_is_respected_and_clamped(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 25) as $day) {
            $this->perform($user, sprintf('2026-09-%02d', $day), 'Plank', [[null, null]], extra: []);
        }
        WorkoutSet::query()->update(['reps' => 1]);

        $this->assertCount(2, $this->history($user, 'plank', '?limit=2')->json('sessions'));
        $this->assertCount(20, $this->history($user, 'plank', '?limit=500')->json('sessions'));
        $this->assertCount(1, $this->history($user, 'plank', '?limit=0')->json('sessions'));
    }

    public function test_exercises_with_no_recorded_sets_are_not_history(): void
    {
        $user = User::factory()->create();
        $this->perform($user, '2026-10-01', 'Row', []);
        $this->perform($user, '2026-10-02', 'Row', [[10, 30]]);

        $sessions = $this->history($user, 'row')->json('sessions');

        $this->assertCount(1, $sessions);
        $this->assertSame('2026-10-02', $sessions[0]['date']);
    }

    public function test_each_entry_says_how_much_to_trust_it(): void
    {
        $user = User::factory()->create();
        $this->perform($user, '2026-10-01', 'Squat', [[10, 50]], 'entered');
        $this->perform($user, '2026-10-02', 'Squat', [[10, 50]], 'as_planned');
        $this->perform($user, '2026-09-01', 'Squat', [[10, 45]], 'migrated');

        $this->assertSame(['as_planned', 'entered', 'migrated'], array_column($this->history($user, 'squat')->json('sessions'), 'recorded_as'));
    }

    public function test_the_planned_target_is_shown_beside_what_was_done(): void
    {
        $user = User::factory()->create();
        $this->perform($user, '2026-10-01', 'Bench Press', [[8, 45]], extra: [
            'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 45, 'planned_duration_seconds' => null,
        ]);
        $this->perform($user, '2026-09-01', 'Bench Press', [[10, 40]]);

        $sessions = $this->history($user, 'bench-press')->json('sessions');

        $this->assertSame(10, $sessions[0]['planned']['reps']);
        $this->assertSame(8, $sessions[0]['sets'][0]['reps']);
        $this->assertNull($sessions[1]['planned']);
    }

    public function test_one_users_history_never_includes_another_users_data(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->perform($alice, '2026-10-01', 'Bench Press', [[10, 40]]);
        $this->perform($bob, '2026-10-02', 'Bench Press', [[5, 100]]);

        $aliceSessions = $this->history($alice, 'bench-press')->json('sessions');
        $bobSessions = $this->history($bob, 'bench-press')->json('sessions');

        $this->assertCount(1, $aliceSessions);
        $this->assertEquals(40, $aliceSessions[0]['sets'][0]['weight_kg']);
        $this->assertCount(1, $bobSessions);
        $this->assertEquals(100, $bobSessions[0]['sets'][0]['weight_kg']);
        $this->assertSame([], $this->history(User::factory()->create(), 'bench-press')->json('sessions'));
    }

    public function test_an_unknown_exercise_returns_an_empty_history_not_an_error(): void
    {
        $this->history(User::factory()->create(), 'never-done')->assertOk()->assertJsonPath('sessions', []);
    }

    public function test_a_name_with_no_usable_characters_is_not_found(): void
    {
        $this->history(User::factory()->create(), '???')->assertNotFound();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/exercises/bench-press/history')->assertUnauthorized();
    }
}
