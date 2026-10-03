<?php

namespace Tests\Support;

use App\Services\Ai\Contracts\ImageGenerator;
use App\Services\Ai\DTOs\GeneratedImage;
use Throwable;

/**
 * Test double for ImageGenerator — no network. Records every prompt so tests
 * can assert both "was Gemini called" and "what was it asked".
 */
class FakeImageGenerator implements ImageGenerator
{
    /** @var string[] */
    public array $prompts = [];

    public function __construct(private readonly ?Throwable $failure = null) {}

    public function generate(string $prompt): GeneratedImage
    {
        $this->prompts[] = $prompt;

        if ($this->failure) {
            throw $this->failure;
        }

        return new GeneratedImage('fake-jpeg-bytes', 'image/jpeg', 'fake-image-model');
    }

    public function callCount(): int
    {
        return count($this->prompts);
    }
}
