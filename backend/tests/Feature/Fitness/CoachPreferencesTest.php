<?php

namespace Tests\Feature\Fitness;

use App\Models\FitnessProfile;
use App\Models\User;
use App\Services\Ai\CoachingPreferences;
use App\Services\Ai\CoachStyle;
use App\Services\Ai\ResponseDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 6: the user's chosen coach voice (style) and response detail live on the existing fitness
 * profile, are validated on the server, belong to one user, and never disturb the rest of the profile.
 */
class CoachPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function save(User $user, array $data)
    {
        return $this->actingAs($user, 'sanctum')->putJson('/api/profile', $data);
    }

    /** A read as its own request: production builds a fresh user (and so a fresh profile relation) each time. */
    private function read(User $user)
    {
        return $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/profile');
    }

    // ---- defaults -------------------------------------------------------------------------------

    public function test_a_new_profile_defaults_to_balanced_and_standard(): void
    {
        $user = User::factory()->create();

        $this->save($user, ['age' => 30])
            ->assertCreated()
            ->assertJsonPath('coach_style', 'balanced')
            ->assertJsonPath('response_detail', 'standard');

        $this->assertDatabaseHas('fitness_profiles', ['user_id' => $user->id, 'coach_style' => 'balanced', 'response_detail' => 'standard']);
    }

    public function test_a_profile_that_existed_before_the_columns_keeps_its_data_and_reads_as_the_defaults(): void
    {
        // Inserted the way an old row looks: without either new column, so the database default applies.
        $user = User::factory()->create();
        DB::table('fitness_profiles')->insert([
            'user_id' => $user->id, 'height_cm' => 170.0, 'age' => 41, 'fitness_level' => 'advanced',
            'primary_goal' => 'Run a 10k', 'equipment' => json_encode(['treadmill']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('coach_style', 'balanced')
            ->assertJsonPath('response_detail', 'standard')
            ->assertJsonPath('primary_goal', 'Run a 10k')
            ->assertJsonPath('age', 41)
            ->assertJsonPath('equipment', ['treadmill']);
    }

    public function test_a_user_with_no_profile_at_all_still_resolves_to_the_defaults(): void
    {
        $preferences = CoachingPreferences::forUser(User::factory()->create());

        $this->assertSame(CoachStyle::Balanced, $preferences->style);
        $this->assertSame(ResponseDetail::Standard, $preferences->detail);
    }

    public function test_the_dashboard_payload_carries_the_saved_values_too(): void
    {
        $user = User::factory()->has(FitnessProfile::factory(['coach_style' => 'intense', 'response_detail' => 'detailed']), 'fitnessProfile')->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('profile.coach_style', 'intense')
            ->assertJsonPath('profile.response_detail', 'detailed');
    }

    // ---- saving ---------------------------------------------------------------------------------

    public function test_every_style_and_every_detail_can_be_saved_and_read_back(): void
    {
        $user = User::factory()->create();

        foreach (CoachStyle::values() as $style) {
            foreach (ResponseDetail::values() as $detail) {
                $this->save($user, ['coach_style' => $style, 'response_detail' => $detail])
                    ->assertSuccessful()
                    ->assertJsonPath('coach_style', $style)
                    ->assertJsonPath('response_detail', $detail);

                $this->read($user)
                    ->assertJsonPath('coach_style', $style)
                    ->assertJsonPath('response_detail', $detail);

                $preferences = CoachingPreferences::forUser($user->fresh());
                $this->assertSame($style, $preferences->style->value);
                $this->assertSame($detail, $preferences->detail->value);
            }
        }

        $this->assertSame(1, FitnessProfile::where('user_id', $user->id)->count());
    }

    public function test_the_two_settings_are_independent(): void
    {
        $user = User::factory()->create();

        $this->save($user, ['coach_style' => 'intense', 'response_detail' => 'concise'])->assertSuccessful();
        $this->save($user, ['response_detail' => 'detailed'])->assertOk()
            ->assertJsonPath('coach_style', 'intense')
            ->assertJsonPath('response_detail', 'detailed');
        $this->save($user, ['coach_style' => 'supportive'])->assertOk()
            ->assertJsonPath('coach_style', 'supportive')
            ->assertJsonPath('response_detail', 'detailed');
    }

    public function test_saving_the_voice_leaves_every_other_profile_value_alone(): void
    {
        $user = User::factory()->has(FitnessProfile::factory([
            'height_cm' => 168.0, 'weight_kg' => 80.0, 'age' => 33, 'sex' => 'female', 'fitness_level' => 'intermediate',
            'primary_goal' => 'Build strength', 'secondary_goal' => 'Sleep better', 'equipment' => ['dumbbells'],
            'preferred_training_days' => [1, 3, 5], 'preferred_training_duration_minutes' => 50,
        ]), 'fitnessProfile')->create();
        $before = $user->fitnessProfile->only(['height_cm', 'weight_kg', 'age', 'sex', 'fitness_level', 'primary_goal', 'secondary_goal', 'equipment', 'preferred_training_days', 'preferred_training_duration_minutes']);

        $this->save($user, ['coach_style' => 'intense'])->assertOk();

        $this->assertEquals($before, $user->fresh()->fitnessProfile->only(array_keys($before)));
    }

    public function test_saving_the_rest_of_the_profile_leaves_the_voice_alone(): void
    {
        $user = User::factory()->create();
        $this->save($user, ['coach_style' => 'intense', 'response_detail' => 'detailed'])->assertSuccessful();

        $this->save($user, ['primary_goal' => 'Run a 10k', 'age' => 35])->assertOk()
            ->assertJsonPath('coach_style', 'intense')
            ->assertJsonPath('response_detail', 'detailed');
    }

    // ---- validation -----------------------------------------------------------------------------

    public function test_unknown_values_are_rejected_and_nothing_is_stored(): void
    {
        $user = User::factory()->create();
        $this->save($user, ['coach_style' => 'balanced'])->assertSuccessful();

        foreach (['coach_style' => ['drill-sergeant', 'INTENSE', '', null, 7, ['intense']], 'response_detail' => ['verbose', 'Detailed', '', null, 1, ['concise']]] as $field => $bad) {
            foreach ($bad as $value) {
                $this->save($user, [$field => $value])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors($field);
            }
        }

        $this->assertDatabaseHas('fitness_profiles', ['user_id' => $user->id, 'coach_style' => 'balanced', 'response_detail' => 'standard']);
    }

    public function test_a_bad_voice_value_rejects_the_whole_request_so_nothing_else_is_half_saved(): void
    {
        $user = User::factory()->has(FitnessProfile::factory(['age' => 30]), 'fitnessProfile')->create();

        $this->save($user, ['age' => 44, 'coach_style' => 'nope'])->assertUnprocessable();

        $this->assertSame(30, $user->fresh()->fitnessProfile->age);
    }

    public function test_a_stored_value_this_code_does_not_recognise_falls_back_instead_of_failing(): void
    {
        $user = User::factory()->has(FitnessProfile::factory(), 'fitnessProfile')->create();
        DB::table('fitness_profiles')->where('user_id', $user->id)->update(['coach_style' => 'retired-style', 'response_detail' => '']);

        $preferences = CoachingPreferences::forUser($user->fresh());

        $this->assertSame(CoachStyle::Balanced, $preferences->style);
        $this->assertSame(ResponseDetail::Standard, $preferences->detail);
    }

    // ---- isolation and authentication -------------------------------------------------------------

    public function test_one_users_choice_never_reaches_another_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->save($alice, ['coach_style' => 'intense', 'response_detail' => 'detailed'])->assertSuccessful();
        $this->save($bob, ['coach_style' => 'supportive'])->assertSuccessful();

        $this->read($alice)->assertJsonPath('coach_style', 'intense')->assertJsonPath('response_detail', 'detailed');
        $this->read($bob)->assertJsonPath('coach_style', 'supportive')->assertJsonPath('response_detail', 'standard');

        $this->assertSame('intense', CoachingPreferences::forUser($alice->fresh())->style->value);
        $this->assertSame('supportive', CoachingPreferences::forUser($bob->fresh())->style->value);
    }

    public function test_a_request_cannot_write_someone_elses_profile(): void
    {
        $alice = User::factory()->has(FitnessProfile::factory(), 'fitnessProfile')->create();
        $bob = User::factory()->has(FitnessProfile::factory(), 'fitnessProfile')->create();

        // user_id and id are not accepted from the client.
        $this->save($bob, ['coach_style' => 'intense', 'user_id' => $alice->id, 'id' => $alice->fitnessProfile->id])->assertOk();

        $this->assertSame('balanced', $alice->fresh()->fitnessProfile->coach_style);
        $this->assertSame('intense', $bob->fresh()->fitnessProfile->coach_style);
    }

    public function test_the_endpoints_require_a_signed_in_user(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->putJson('/api/profile', ['coach_style' => 'intense'])->assertUnauthorized();
    }
}
