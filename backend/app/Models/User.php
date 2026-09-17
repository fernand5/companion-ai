<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'timezone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The single source of truth for "what time is it for this user" — every
     * "today"/"now" computation across the app that decides which calendar
     * date an activity, plan, or context snapshot belongs to must go through
     * this (or localToday()), never a bare Carbon::today()/now(). Those read
     * the Laravel app timezone (UTC), which silently diverges from the
     * user's actual calendar day for hours around their local midnight —
     * e.g. 7pm-midnight in America/Bogota (UTC-5) is already "tomorrow" in
     * UTC, misdating anything logged as "today" during that window.
     */
    public function localNow(): Carbon
    {
        return Carbon::now($this->timezone ?: config('app.timezone'));
    }

    public function localToday(): string
    {
        return $this->localNow()->toDateString();
    }

    public function fitnessProfile(): HasOne
    {
        return $this->hasOne(FitnessProfile::class);
    }

    public function trainingSchedules(): HasMany
    {
        return $this->hasMany(TrainingSchedule::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function workoutSessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }

    public function workoutPlans(): HasMany
    {
        return $this->hasMany(WorkoutPlan::class);
    }

    public function recoveryCheckins(): HasMany
    {
        return $this->hasMany(RecoveryCheckin::class);
    }

    public function weeklySummaries(): HasMany
    {
        return $this->hasMany(WeeklySummary::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function fitnessMemories(): HasMany
    {
        return $this->hasMany(FitnessMemory::class);
    }
}
