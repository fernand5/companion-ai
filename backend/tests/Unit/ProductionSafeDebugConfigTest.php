<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * config/app.php and config/ai.php are plain arrays evaluated once at boot,
 * so testing the production guard means re-evaluating the file fresh with
 * APP_ENV/APP_DEBUG/AI_DEBUG overridden — reading config('app.debug') after
 * boot would just reflect whatever was computed under the testing env.
 *
 * Laravel's env() helper reads $_SERVER ahead of $_ENV (both populated once,
 * at process start, by PHPUnit's <env> tags / Dotenv), so both must be set
 * directly here — putenv() or $_ENV alone aren't reliably observed.
 */
class ProductionSafeDebugConfigTest extends TestCase
{
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['APP_ENV', 'APP_DEBUG', 'AI_DEBUG'] as $key) {
            $this->original[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $key => [$env, $server]) {
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }

            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
        }

        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_app_debug_is_hard_disabled_in_production_even_if_app_debug_env_is_true(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('APP_DEBUG', 'true');

        $config = require base_path('config/app.php');

        $this->assertFalse($config['debug']);
    }

    public function test_app_debug_still_respects_app_debug_env_outside_production(): void
    {
        $this->setEnv('APP_ENV', 'local');
        $this->setEnv('APP_DEBUG', 'true');

        $config = require base_path('config/app.php');

        $this->assertTrue($config['debug']);
    }

    public function test_ai_debug_is_hard_disabled_in_production_even_if_ai_debug_env_is_true(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('AI_DEBUG', 'true');

        $config = require base_path('config/ai.php');

        $this->assertFalse($config['debug']);
    }

    public function test_ai_debug_still_respects_ai_debug_env_outside_production(): void
    {
        $this->setEnv('APP_ENV', 'local');
        $this->setEnv('AI_DEBUG', 'true');

        $config = require base_path('config/ai.php');

        $this->assertTrue($config['debug']);
    }
}
