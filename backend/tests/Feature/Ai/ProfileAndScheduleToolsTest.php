<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Services\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileAndScheduleToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_fitness_profile_persists_only_the_given_fields(): void
    {
        $user = User::factory()->create();

        $result = app(ToolRegistry::class)->call('update_fitness_profile', [
            'height_cm' => 168,
            'primary_goal' => 'Lose body fat while maintaining muscle',
            'equipment' => ['2x 6kg dumbbells', 'treadmill', 'exercise mat'],
        ], $user);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertDatabaseHas('fitness_profiles', [
            'user_id' => $user->id,
            'height_cm' => 168,
            'primary_goal' => 'Lose body fat while maintaining muscle',
        ]);
        $this->assertSame(['2x 6kg dumbbells', 'treadmill', 'exercise mat'], $user->fresh()->fitnessProfile->equipment);
    }

    public function test_update_fitness_profile_does_not_overwrite_unmentioned_fields(): void
    {
        $user = User::factory()->create();
        $registry = app(ToolRegistry::class);

        $registry->call('update_fitness_profile', ['primary_goal' => 'Fat loss'], $user);
        $registry->call('update_fitness_profile', ['fitness_level' => 'intermediate'], $user);

        $profile = $user->fresh()->fitnessProfile;
        $this->assertSame('Fat loss', $profile->primary_goal);
        $this->assertSame('intermediate', $profile->fitness_level);
    }

    public function test_update_fitness_profile_is_scoped_to_the_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        app(ToolRegistry::class)->call('update_fitness_profile', ['primary_goal' => 'Intruder goal'], $intruder);

        $this->assertNull($owner->fresh()->fitnessProfile);
        $this->assertSame('Intruder goal', $intruder->fresh()->fitnessProfile->primary_goal);
    }

    public function test_add_training_schedule_entry_accepts_a_day_name(): void
    {
        $user = User::factory()->create();

        $result = app(ToolRegistry::class)->call('add_training_schedule_entry', [
            'activity_type' => 'Soccer',
            'day_of_week' => 'Tuesday',
        ], $user);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['day_of_week']);
        $this->assertSame('18:00', $result['start_time']);
        $this->assertDatabaseHas('training_schedules', [
            'user_id' => $user->id,
            'activity_type' => 'Soccer',
            'day_of_week' => 2,
        ]);
    }

    public function test_add_training_schedule_entry_called_twice_for_different_days_creates_two_rows(): void
    {
        $user = User::factory()->create();
        $registry = app(ToolRegistry::class);

        $registry->call('add_training_schedule_entry', ['activity_type' => 'Soccer', 'day_of_week' => 'Tuesday'], $user);
        $registry->call('add_training_schedule_entry', ['activity_type' => 'Soccer', 'day_of_week' => 'Thursday'], $user);

        $this->assertSame(2, $user->trainingSchedules()->count());
    }

    public function test_add_training_schedule_entry_re_stating_the_same_day_updates_instead_of_duplicating(): void
    {
        $user = User::factory()->create();
        $registry = app(ToolRegistry::class);

        $registry->call('add_training_schedule_entry', ['activity_type' => 'Soccer', 'day_of_week' => 'Tuesday', 'start_time' => '19:00'], $user);
        $registry->call('add_training_schedule_entry', ['activity_type' => 'soccer', 'day_of_week' => 'Tuesday', 'start_time' => '20:00'], $user);

        $this->assertSame(1, $user->trainingSchedules()->count());
        $this->assertSame('20:00', substr((string) $user->trainingSchedules()->first()->start_time, 0, 5));
    }

    public function test_add_training_schedule_entry_rejects_an_unrecognized_day(): void
    {
        $user = User::factory()->create();

        $result = app(ToolRegistry::class)->call('add_training_schedule_entry', [
            'activity_type' => 'Soccer',
            'day_of_week' => 'Blursday',
        ], $user);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_add_training_schedule_entry_is_scoped_to_the_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        app(ToolRegistry::class)->call('add_training_schedule_entry', ['activity_type' => 'Soccer', 'day_of_week' => 'Tuesday'], $intruder);

        $this->assertSame(0, $owner->trainingSchedules()->count());
        $this->assertSame(1, $intruder->trainingSchedules()->count());
    }
}
