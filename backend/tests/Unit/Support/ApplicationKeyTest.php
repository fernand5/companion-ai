<?php

namespace Tests\Unit\Support;

use App\Support\ApplicationKey;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationKeyTest extends TestCase
{
    /** @return array<string, array{mixed, bool}> */
    public static function keys(): array
    {
        return [
            'a base64 32-byte key (what `key:generate` writes)' => ['base64:'.'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=', true],
            'a raw 32-character key' => [str_repeat('k', 32), true],
            'missing (null)' => [null, false],
            'empty' => ['', false],
            'only the prefix' => ['base64:', false],
            'base64: with characters that are not base64' => ['base64:!!!not*base64!!!', false],
            'base64 of too few bytes' => ['base64:'.base64_encode('short'), false],
            'base64 of 16 bytes (wrong for AES-256)' => ['base64:'.base64_encode(str_repeat('k', 16)), false],
            'a plain string of the wrong length' => ['plainstringofthewronglength', false],
        ];
    }

    #[DataProvider('keys')]
    public function test_it_tells_a_usable_key_from_one_that_is_not(mixed $key, bool $expected): void
    {
        config(['app.key' => $key, 'app.cipher' => 'AES-256-CBC']);

        $this->assertSame($expected, ApplicationKey::usable());
    }

    public function test_it_follows_the_configured_cipher(): void
    {
        config(['app.cipher' => 'AES-128-CBC', 'app.key' => 'base64:'.base64_encode(str_repeat('k', 16))]);
        $this->assertTrue(ApplicationKey::usable());

        config(['app.cipher' => 'AES-256-CBC']);
        $this->assertFalse(ApplicationKey::usable());
    }
}
