<?php

use App\Support\ExerciseSlug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workout_plan_exercises', function (Blueprint $table) {
            $table->string('exercise_slug', 100)->nullable()->after('exercise_name')->index();
        });

        // Existing rows predate the column; new/renamed rows are handled by the
        // model's saving hook.
        DB::table('workout_plan_exercises')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('workout_plan_exercises')
                    ->where('id', $row->id)
                    ->update(['exercise_slug' => ExerciseSlug::from($row->exercise_name)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('workout_plan_exercises', function (Blueprint $table) {
            $table->dropIndex(['exercise_slug']);
            $table->dropColumn('exercise_slug');
        });
    }
};
