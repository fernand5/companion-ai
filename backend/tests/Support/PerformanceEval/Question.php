<?php

namespace Tests\Support\PerformanceEval;

final class Question
{
    /**
     * @param  array<int, Check>  $checks
     * @param  array<string, array{text: string, fails: array<int, string>}>  $bad  answers that must be caught, by the dimensions they must fail
     * @param  array<int, string>  $goodTools  tools the known-good answer is assumed to have called
     * @param  array<int, string>  $alsoGood  real model answers a person judged correct: the checks must accept them (calibration against false alarms)
     */
    public function __construct(
        public readonly string $text,
        public readonly string $expected,
        public readonly array $checks,
        public readonly string $good,
        public readonly array $bad = [],
        public readonly array $goodTools = [],
        public readonly array $alsoGood = [],
    ) {}
}
