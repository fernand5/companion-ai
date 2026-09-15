<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\ScheduleService;
use Illuminate\Validation\ValidationException;

class AddTrainingScheduleTool implements AiTool
{
    private const DAY_NAMES = [
        'sunday' => 0, 'sun' => 0,
        'monday' => 1, 'mon' => 1,
        'tuesday' => 2, 'tue' => 2, 'tues' => 2,
        'wednesday' => 3, 'wed' => 3,
        'thursday' => 4, 'thu' => 4, 'thurs' => 4,
        'friday' => 5, 'fri' => 5,
        'saturday' => 6, 'sat' => 6,
    ];

    public function __construct(private readonly ScheduleService $scheduleService) {}

    public function name(): string
    {
        return 'add_training_schedule_entry';
    }

    public function description(): string
    {
        return 'Add (or update) one recurring weekly commitment on the user\'s schedule, e.g. "soccer every '
            .'Tuesday at 8pm". Call once per distinct day — if the user plays twice a week, call this twice. '
            .'Use for regular weekly commitments, not one-off events. If the user doesn\'t give a time, default '
            .'to a reasonable evening time (18:00) rather than skipping the entry.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'activity_type' => ['type' => 'string', 'description' => 'e.g. Soccer, Football, Strength.'],
                'day_of_week' => ['type' => 'string', 'description' => 'Day name (e.g. "Tuesday") or 0-6 (0=Sunday).'],
                'start_time' => ['type' => 'string', 'description' => 'HH:MM 24-hour, defaults to 18:00 if omitted.'],
                'expected_duration_minutes' => ['type' => 'integer', 'description' => 'Defaults to 60 if omitted.'],
                'intensity' => ['type' => 'string', 'description' => 'One of: low, moderate, high.'],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['activity_type', 'day_of_week'],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        if (empty($arguments['activity_type']) || ! isset($arguments['day_of_week'])) {
            return ['error' => 'activity_type and day_of_week are required.'];
        }

        $dayOfWeek = $this->resolveDayOfWeek($arguments['day_of_week']);

        if ($dayOfWeek === null) {
            return ['error' => "Unrecognized day_of_week: \"{$arguments['day_of_week']}\"."];
        }

        try {
            $schedule = $this->scheduleService->create($user, [
                'activity_type' => $arguments['activity_type'],
                'day_of_week' => $dayOfWeek,
                'start_time' => $arguments['start_time'] ?? '18:00',
                'expected_duration_minutes' => $arguments['expected_duration_minutes'] ?? 60,
                'intensity' => $arguments['intensity'] ?? null,
                'notes' => $arguments['notes'] ?? null,
            ]);
        } catch (ValidationException $e) {
            return ['error' => implode(' ', $e->validator->errors()->all())];
        }

        return [
            'activity_type' => $schedule->activity_type,
            'day_of_week' => $schedule->day_of_week,
            'start_time' => substr((string) $schedule->start_time, 0, 5),
            'expected_duration_minutes' => $schedule->expected_duration_minutes,
            'intensity' => $schedule->intensity,
        ];
    }

    private function resolveDayOfWeek(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $day = (int) $value;

            return ($day >= 0 && $day <= 6) ? $day : null;
        }

        return self::DAY_NAMES[mb_strtolower(trim((string) $value))] ?? null;
    }
}
