<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\DTOs\GeneratedImage;

/**
 * Kept separate from AiProvider on purpose: image generation has a different
 * model, timeout and response shape from chat, and adding it to AiProvider
 * would force every chat implementation and test double to change.
 */
interface ImageGenerator
{
    /**
     * @throws AiProviderException when no usable image could be produced
     */
    public function generate(string $prompt): GeneratedImage;
}
