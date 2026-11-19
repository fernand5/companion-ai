<?php

namespace Tests\Unit\Ai\Authorization;

use App\Services\Ai\Authorization\LoadNumber;
use App\Services\Ai\Authorization\PlanChangeIntentClassifier;
use PHPUnit\Framework\TestCase;

/** "42.5" and "42,5" are 42.5, never 425 or 42; a figure that can be read two ways is not read at all. */
class LoadNumberTest extends TestCase
{
    public function test_a_decimal_is_read_with_either_mark(): void
    {
        $this->assertSame(42.5, LoadNumber::parse('42.5'));
        $this->assertSame(42.5, LoadNumber::parse('42,5'));
        $this->assertSame(42.0, LoadNumber::parse('42'));
        $this->assertSame(2.25, LoadNumber::parse('2,25'));
        $this->assertSame([42.5], LoadNumber::kilograms('Sube a 42,5 kg en press'));
        $this->assertSame([42.5], LoadNumber::kilograms('Raise to 42.5 kg'));
    }

    public function test_an_ambiguous_figure_is_not_a_number(): void
    {
        foreach (['1,250', '1.250', '1.234,5', '1,2,3', '42,555', '4,2.5'] as $ambiguous) {
            $this->assertNull(LoadNumber::parse($ambiguous), $ambiguous);
        }

        $this->assertSame([], LoadNumber::kilograms('Sube a 1,250 kg'));
        $this->assertSame(1, LoadNumber::countKilograms('Sube a 1,250 kg'), 'Unreadable, but it still counts as a figure the user wrote.');
    }

    public function test_a_load_stated_in_an_instruction_is_only_ever_read_one_way(): void
    {
        $authorization = (new PlanChangeIntentClassifier)->classify('Actualiza mi plan: press de banca a 42,5 kg');
        $this->assertSame([42.5], $authorization->statedLoadsKg);

        $this->assertSame([], (new PlanChangeIntentClassifier)->classify('Actualiza mi plan: press de banca a 1,250 kg')->statedLoadsKg, 'No invented starting weight.');
    }
}
