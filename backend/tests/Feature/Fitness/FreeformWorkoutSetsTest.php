<?php

namespace Tests\Feature\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Freeform logging ("I did bench 3 x 10 at 40 kg") now produces real set rows.
 */
class FreeformWorkoutSetsTest extends TestCase
{
    use RefreshDatabase;

    private function log(User $user, array $payload)
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/workouts', $payload);
    }

    public function test_a_sets_reps_weight_entry_becomes_one_row_per_set_marked_entered(): void
    {
        $user = User::factory()->create();

        $response = $this->log($user, ['exercises' => [['exercise_name' => 'Bench Press', 'sets' => 3, 'reps' => 10, 'weight_kg' => 40]]])->assertCreated();

        $exercise = $response->json('exercises.0');
        $this->assertSame('entered', $exercise['recorded_as']);
        $this->assertSame('bench-press', $exercise['exercise_slug']);
        $this->assertCount(3, $exercise['performed_sets']);
        $this->assertSame([1, 2, 3], array_column($exercise['performed_sets'], 'set_number'));
        $this->assertSame([10, 10, 10], array_column($exercise['performed_sets'], 'reps'));
        $this->assertSame(3, $exercise['sets']);
    }

    public function test_per_set_details_record_exactly_what_was_done(): void
    {
        $user = User::factory()->create();

        $response = $this->log($user, ['exercises' => [[
            'exercise_name' => 'Bench Press',
            'set_details' => [
                ['reps' => 10, 'weight_kg' => 45],
                ['reps' => 8, 'weight_kg' => 45],
                ['reps' => 7, 'weight_kg' => 45],
            ],
        ]]])->assertCreated();

        $exercise = $response->json('exercises.0');
        $this->assertSame([10, 8, 7], array_column($exercise['performed_sets'], 'reps'));
        // The summary is derived from the sets, not typed separately.
        $this->assertSame(3, $exercise['sets']);
        $this->assertSame(10, $exercise['reps']);
        $this->assertEquals(45, $exercise['weight_kg']);
    }

    public function test_a_bare_set_count_is_kept_without_inventing_set_values(): void
    {
        $user = User::factory()->create();

        $exercise = $this->log($user, ['exercises' => [['exercise_name' => 'Pull-ups', 'sets' => 3]]])->assertCreated()->json('exercises.0');

        $this->assertSame([], $exercise['performed_sets']);
        $this->assertSame(3, $exercise['sets']);
        $this->assertNull($exercise['reps']);
    }

    public function test_reps_without_a_set_count_are_read_as_a_single_set(): void
    {
        $user = User::factory()->create();

        $exercise = $this->log($user, ['exercises' => [['exercise_name' => 'Deadlift', 'reps' => 5, 'weight_kg' => 100]]])->assertCreated()->json('exercises.0');

        $this->assertCount(1, $exercise['performed_sets']);
    }

    public function test_the_session_duration_and_mirrored_activity_are_recorded_as_given(): void
    {
        $user = User::factory()->create();

        $this->log($user, ['duration_minutes' => 50, 'exercises' => [['exercise_name' => 'Row', 'sets' => 3, 'reps' => 10]]])->assertCreated();

        $this->assertSame(50, WorkoutSession::first()->duration_minutes);
        $log = ActivityLog::where('type', 'strength')->first();
        $this->assertSame(50, $log->duration_minutes);
        $this->assertSame(1, $log->metadata['exercise_count']);
    }

    public function test_per_set_values_are_validated(): void
    {
        $user = User::factory()->create();

        $this->log($user, ['exercises' => [['exercise_name' => 'Row', 'set_details' => [['reps' => 500]]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('exercises.0.set_details.0.reps');
        $this->log($user, ['exercises' => [['exercise_name' => 'Row', 'set_details' => array_fill(0, 51, ['reps' => 5])]]])
            ->assertUnprocessable();
        $this->assertSame(0, WorkoutSession::count());
    }

    public function test_future_sessions_are_still_rejected(): void
    {
        $user = User::factory()->create();

        $this->log($user, ['logged_date' => now()->addDay()->toDateString(), 'exercises' => [['exercise_name' => 'Row', 'sets' => 3]]])
            ->assertUnprocessable()->assertJsonValidationErrors('logged_date');
    }

    public function test_listing_sessions_includes_the_sets_without_per_row_queries(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 4) as $_) {
            $this->log($user, ['exercises' => [['exercise_name' => 'Row', 'sets' => 3, 'reps' => 10, 'weight_kg' => 30]]])->assertCreated();
        }

        \DB::enableQueryLog();
        $sessions = $this->actingAs($user, 'sanctum')->getJson('/api/workouts')->assertOk()->json();
        $setQueries = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'workout_sets'))->count();

        $this->assertCount(4, $sessions);
        $this->assertCount(3, $sessions[0]['exercises'][0]['performed_sets']);
        $this->assertSame(1, $setQueries);
    }
}
