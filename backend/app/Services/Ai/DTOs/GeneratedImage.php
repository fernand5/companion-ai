<?php

namespace App\Services\Ai\DTOs;

final class GeneratedImage
{
    private const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType,
        public readonly string $model,
    ) {}

    public function extension(): string
    {
        return self::EXTENSIONS[$this->mimeType] ?? 'bin';
    }
}
