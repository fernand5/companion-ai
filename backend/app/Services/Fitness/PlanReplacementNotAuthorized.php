<?php

namespace App\Services\Fitness;

use RuntimeException;

/**
 * A write would have replaced a day's existing plan and nothing authorized the replacement. Replacing a plan
 * wholesale is never a side effect: the caller must say, explicitly, that the user authorized it
 * (see WorkoutPlanService::createOrReplace()).
 */
class PlanReplacementNotAuthorized extends RuntimeException
{
    public function __construct(public readonly string $date)
    {
        parent::__construct("{$date} already has a plan and replacing it was not authorized.");
    }
}
