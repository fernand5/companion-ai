<?php

namespace App\Services\Fitness\Progression;

use RuntimeException;

/** Approval proposals cannot be sealed or opened right now (the application key is unusable). */
final class ProgressionUnavailableException extends RuntimeException {}
