<?php

namespace App\Services\Ai\Authorization;

/** Thrown inside PlanReplacer's transaction when what was written is not what was shown: the whole change is rolled back. */
final class PlanNotPersisted extends \RuntimeException {}
