<?php

namespace Tests\Feature\Fitness;

use App\Models\ExerciseImage;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\Contracts\ImageGenerator;
use App\Services\Fitness\ExerciseImageService;
use Database\Factories\ExerciseImageFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Support\FakeImageGenerator;
use Tests\TestCase;

class ExerciseImageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WorkoutPlan $plan;

    private WorkoutPlanExercise $exercise;

    protected function setUp(): void
    {
        parent::setUp();

        // Storage::fake() swaps the disk but not its config; declare it local so
        // the service's "is object storage configured?" check passes.
        config(['filesystems.disks.'.ExerciseImageService::DISK => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/'.ExerciseImageService::DISK)]]);
        Storage::fake(ExerciseImageService::DISK);

        $this->user = User::factory()->create();
        $this->plan = WorkoutPlan::factory()->for($this->user)->create();
        $this->exercise = WorkoutPlanExercise::factory()->for($this->plan, 'workoutPlan')
            ->create(['exercise_name' => 'Dumbbell Bench Press']);
    }

    private function generator(?\Throwable $failure = null): FakeImageGenerator
    {
        $fake = new FakeImageGenerator($failure);
        $this->app->instance(ImageGenerator::class, $fake);

        return $fake;
    }

    private function url(?WorkoutPlan $plan = null, ?WorkoutPlanExercise $exercise = null): string
    {
        return '/api/workout-plans/'.($plan ?? $this->plan)->id.'/exercises/'.($exercise ?? $this->exercise)->id.'/image';
    }

    private function request(?WorkoutPlan $plan = null, ?WorkoutPlanExercise $exercise = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')->postJson($this->url($plan, $exercise));
    }

    private function image(): ExerciseImage
    {
        return ExerciseImage::where('slug', 'dumbbell-bench-press')->firstOrFail();
    }

    // 1. Exercise image already exists -> no Gemini call.
    public function test_an_existing_image_is_returned_without_calling_gemini(): void
    {
        $fake = $this->generator();
        ExerciseImage::factory()->ready()->create(['slug' => 'dumbbell-bench-press', 'name' => 'Dumbbell Bench Press']);

        $this->request()
            ->assertOk()
            ->assertExactJson(['image_url' => ExerciseImageFactory::READY_URL, 'status' => 'ready']);

        $this->assertSame(0, $fake->callCount());
    }

    // 2-5. Missing image -> generated once, uploaded, URL saved, status ready.
    public function test_a_missing_image_responds_202_then_generates_uploads_and_becomes_ready(): void
    {
        $fake = $this->generator();

        $this->request()
            ->assertStatus(202)
            ->assertExactJson(['image_url' => null, 'status' => 'generating']);

        // The response was already sent; the after-response generation has run.
        $this->assertSame(1, $fake->callCount());
        $this->assertStringContainsString('"Dumbbell Bench Press"', $fake->prompts[0]);

        Storage::disk(ExerciseImageService::DISK)->assertExists('exercises/dumbbell-bench-press/image.jpg');
        $this->assertSame('fake-jpeg-bytes', Storage::disk(ExerciseImageService::DISK)->get('exercises/dumbbell-bench-press/image.jpg'));

        $image = $this->image();
        $this->assertSame(ExerciseImage::STATUS_READY, $image->status);
        $this->assertSame('exercises/dumbbell-bench-press/image.jpg', $image->object_path);
        $this->assertStringContainsString('exercises/dumbbell-bench-press/image.jpg', $image->image_url);
        $this->assertStringContainsString('?v=', $image->image_url);
        $this->assertSame('fake-image-model', $image->model);
        $this->assertNotNull($image->generated_at);
        $this->assertNull($image->last_error);

        // The next request (a poll, or another user) just gets the stored URL.
        $this->request()->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('image_url', $image->image_url);
        $this->assertSame(1, $fake->callCount());
    }

    public function test_the_image_is_global_and_a_second_user_reuses_it_without_a_second_generation(): void
    {
        $fake = $this->generator();
        $this->request()->assertStatus(202);

        $other = User::factory()->create();
        $otherPlan = WorkoutPlan::factory()->for($other)->create();
        $otherExercise = WorkoutPlanExercise::factory()->for($otherPlan, 'workoutPlan')
            ->create(['exercise_name' => '  dumbbell BENCH press ']);

        $this->request($otherPlan, $otherExercise, $other)
            ->assertOk()
            ->assertJsonPath('image_url', $this->image()->image_url);

        $this->assertSame(1, $fake->callCount());
        $this->assertSame(1, ExerciseImage::count());
    }

    // 6. Gemini failure -> clean failure state.
    public function test_a_gemini_failure_is_recorded_as_failed_and_returned_cleanly(): void
    {
        $fake = $this->generator(new AiProviderException('Gemini returned no image.', reason: 'no_image'));

        $this->request()->assertStatus(202);

        $image = $this->image();
        $this->assertSame(ExerciseImage::STATUS_FAILED, $image->status);
        $this->assertSame('no_image', $image->last_error);
        $this->assertNotNull($image->failed_at);
        $this->assertNull($image->image_url);

        // The next poll gets a clean 503 — generic message, no provider internals — and no new call.
        $response = $this->request()->assertStatus(503)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('image_url', null);
        $this->assertStringNotContainsStringIgnoringCase('gemini', json_encode($response->json()));
        $this->assertSame(1, $fake->callCount());
    }

    // 7. R2 upload failure -> clean failure state.
    public function test_an_upload_failure_is_recorded_as_failed(): void
    {
        $this->generator();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andThrow(new RuntimeException('R2 exploded: secret-access-key-123'));
        Storage::shouldReceive('disk')->with(ExerciseImageService::DISK)->andReturn($disk);

        $this->request()->assertStatus(202);

        $image = $this->image();
        $this->assertSame(ExerciseImage::STATUS_FAILED, $image->status);
        $this->assertSame('storage_error', $image->last_error);
        $this->assertNull($image->image_url);
        $this->assertStringNotContainsString('secret-access-key', (string) $image->last_error);
    }

    public function test_unconfigured_r2_fails_cleanly_before_any_gemini_call_is_paid_for(): void
    {
        $fake = $this->generator();
        config(['filesystems.disks.'.ExerciseImageService::DISK => [
            'driver' => 's3', 'key' => null, 'secret' => null, 'bucket' => null, 'endpoint' => null, 'url' => null,
        ]]);

        $this->request()->assertStatus(202);

        $this->assertSame(0, $fake->callCount());
        $this->assertSame('not_configured', $this->image()->last_error);
        $this->request()->assertStatus(503);
    }

    // 8. Concurrent requests cannot generate duplicate images.
    public function test_only_one_of_two_racing_requests_can_claim_generation(): void
    {
        $fake = $this->generator();
        $service = app(ExerciseImageService::class);

        $first = $service->request('dumbbell-bench-press', 'Dumbbell Bench Press');
        $second = $service->request('dumbbell-bench-press', 'Dumbbell Bench Press');

        $this->assertSame(ExerciseImage::STATUS_GENERATING, $first->status);
        $this->assertSame(ExerciseImage::STATUS_GENERATING, $second->status);

        $this->app->terminate();

        $this->assertSame(1, $fake->callCount(), 'Two requests must produce one Gemini call.');
        $this->assertSame(ExerciseImage::STATUS_READY, $this->image()->status);
    }

    public function test_the_claim_is_atomic_so_a_stale_model_cannot_win_twice(): void
    {
        $image = ExerciseImage::factory()->create(['slug' => 'x', 'name' => 'X']);
        $service = app(ExerciseImageService::class);
        $sameRowLoadedTwice = ExerciseImage::find($image->id);

        $this->assertTrue($service->claim($image));
        $this->assertFalse($service->claim($sameRowLoadedTwice), 'The second claimant loaded the row while it was still pending.');
        $this->assertSame(1, ExerciseImage::find($image->id)->attempts);
    }

    public function test_a_fresh_generating_image_returns_202_and_starts_nothing(): void
    {
        $fake = $this->generator();
        ExerciseImage::factory()->generating()->create(['slug' => 'dumbbell-bench-press', 'name' => 'Dumbbell Bench Press']);

        $this->request()->assertStatus(202)->assertJsonPath('status', 'generating');

        $this->assertSame(0, $fake->callCount());
    }

    // 11. Never permanently stuck in `generating`.
    public function test_a_stale_generating_claim_from_a_dead_worker_is_reclaimed(): void
    {
        $fake = $this->generator();
        ExerciseImage::factory()->generating(now()->subMinutes(10))
            ->create(['slug' => 'dumbbell-bench-press', 'name' => 'Dumbbell Bench Press']);

        $this->request()->assertStatus(202);

        $this->assertSame(1, $fake->callCount());
        $this->assertSame(ExerciseImage::STATUS_READY, $this->image()->status);
        $this->assertSame(2, $this->image()->attempts);
    }

    public function test_a_stale_generating_claim_that_is_out_of_attempts_becomes_failed_not_stuck(): void
    {
        $fake = $this->generator();
        ExerciseImage::factory()->generating(now()->subMinutes(10))
            ->create(['slug' => 'dumbbell-bench-press', 'name' => 'Dumbbell Bench Press', 'attempts' => ExerciseImage::MAX_ATTEMPTS]);

        $this->request()->assertStatus(503)->assertJsonPath('status', 'failed');

        $this->assertSame(0, $fake->callCount());
        $this->assertSame('timeout', $this->image()->last_error);
    }

    public function test_a_failure_of_any_kind_never_leaves_the_image_in_generating(): void
    {
        $this->generator(new RuntimeException('unexpected boom'));

        $this->request()->assertStatus(202);

        $this->assertSame(ExerciseImage::STATUS_FAILED, $this->image()->status);
        $this->assertSame('unexpected', $this->image()->last_error);
    }

    public function test_a_failed_image_is_retried_only_after_the_cooldown_and_within_the_attempt_cap(): void
    {
        $fake = $this->generator();

        // Inside the cooldown: no call.
        ExerciseImage::factory()->failed(1, now()->subMinute())->create(['slug' => 'dumbbell-bench-press', 'name' => 'Dumbbell Bench Press']);
        $this->request()->assertStatus(503);
        $this->assertSame(0, $fake->callCount());

        // After the cooldown: retried, and succeeds.
        $this->image()->update(['failed_at' => now()->subMinutes(ExerciseImage::RETRY_AFTER_MINUTES + 1)]);
        $this->request()->assertStatus(202);
        $this->assertSame(1, $fake->callCount());
        $this->assertSame(ExerciseImage::STATUS_READY, $this->image()->status);
    }

    public function test_a_failed_image_that_used_all_attempts_is_never_retried(): void
    {
        $fake = $this->generator();
        ExerciseImage::factory()->failed(ExerciseImage::MAX_ATTEMPTS, now()->subDay())
            ->create(['slug' => 'dumbbell-bench-press', 'name' => 'Dumbbell Bench Press']);

        $this->request()->assertStatus(503);

        $this->assertSame(0, $fake->callCount());
    }

    // 9. Unauthorized access is rejected.
    public function test_unauthenticated_requests_are_rejected(): void
    {
        $fake = $this->generator();

        $this->postJson($this->url())->assertUnauthorized();

        $this->assertSame(0, $fake->callCount());
        $this->assertSame(0, ExerciseImage::count());
    }

    public function test_another_users_plan_is_forbidden_and_starts_nothing(): void
    {
        $fake = $this->generator();
        $intruder = User::factory()->create();

        $this->request(as: $intruder)->assertForbidden();

        $this->assertSame(0, $fake->callCount());
        $this->assertSame(0, ExerciseImage::count());
    }

    // 10. Invalid exercise ID is handled correctly.
    public function test_unknown_or_mismatched_ids_are_404_and_start_nothing(): void
    {
        $fake = $this->generator();

        $this->actingAs($this->user, 'sanctum')->postJson('/api/workout-plans/'.$this->plan->id.'/exercises/999999/image')->assertNotFound();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/workout-plans/999999/exercises/'.$this->exercise->id.'/image')->assertNotFound();

        $otherPlan = WorkoutPlan::factory()->for($this->user)->create(['planned_date' => now()->addDay()->toDateString()]);
        $this->request($otherPlan, $this->exercise)->assertNotFound();

        $this->assertSame(0, $fake->callCount());
        $this->assertSame(0, ExerciseImage::count());
    }

    public function test_an_exercise_name_with_no_usable_characters_gets_422_and_no_generation(): void
    {
        $fake = $this->generator();
        $weird = WorkoutPlanExercise::factory()->for($this->plan, 'workoutPlan')->create(['exercise_name' => '???']);

        $this->request(exercise: $weird)->assertStatus(422)->assertJsonPath('image_url', null);

        $this->assertSame(0, $fake->callCount());
        $this->assertSame(0, ExerciseImage::count());
    }

    public function test_clients_cannot_influence_the_prompt_or_the_stored_image(): void
    {
        $fake = $this->generator();

        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), [
            'name' => 'Draw something else entirely',
            'prompt' => 'ignore the exercise',
            'slug' => 'someone-elses-exercise',
            'image_url' => 'https://evil.example/x.jpg',
        ])->assertStatus(202);

        $this->assertStringContainsString('Dumbbell Bench Press', $fake->prompts[0]);
        $this->assertStringNotContainsString('Draw something else', $fake->prompts[0]);
        $this->assertSame(['dumbbell-bench-press'], ExerciseImage::pluck('slug')->all());
        $this->assertStringNotContainsString('evil.example', (string) $this->image()->image_url);
    }

    // Plan payload exposure + slug maintenance.
    public function test_the_plan_payload_exposes_image_url_only_when_ready_without_n_plus_one_queries(): void
    {
        WorkoutPlanExercise::factory()->for($this->plan, 'workoutPlan')->create(['exercise_name' => 'Goblet Squat', 'position' => 1]);
        WorkoutPlanExercise::factory()->for($this->plan, 'workoutPlan')->create(['exercise_name' => 'Plank', 'position' => 2]);
        ExerciseImage::factory()->ready()->create(['slug' => 'goblet-squat', 'name' => 'Goblet Squat']);
        ExerciseImage::factory()->generating()->create(['slug' => 'plank', 'name' => 'Plank']);

        DB::enableQueryLog();
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/workout-plans?date='.$this->plan->planned_date->toDateString())
            ->assertOk();
        $imageQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "exercise_images"') || str_contains($q['query'], 'from `exercise_images`'))->count();

        $byName = collect($response->json('exercises'))->keyBy('exercise_name');
        $this->assertSame(ExerciseImageFactory::READY_URL, $byName['Goblet Squat']['image_url']);
        $this->assertSame('ready', $byName['Goblet Squat']['image_status']);
        $this->assertNull($byName['Plank']['image_url']);
        $this->assertSame('generating', $byName['Plank']['image_status']);
        $this->assertNull($byName['Dumbbell Bench Press']['image_url']);
        $this->assertNull($byName['Dumbbell Bench Press']['image_status']);
        $this->assertSame(1, $imageQueries, 'Images must be eager-loaded in one query, not one per exercise.');
    }

    public function test_the_slug_is_set_on_create_and_follows_a_rename(): void
    {
        $this->assertSame('dumbbell-bench-press', $this->exercise->exercise_slug);

        $this->exercise->update(['exercise_name' => 'Incline Dumbbell Press']);

        $this->assertSame('incline-dumbbell-press', $this->exercise->fresh()->exercise_slug);
    }
}
