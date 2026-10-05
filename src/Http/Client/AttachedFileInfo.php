<?php

namespace Caixingyue\LaravelStarLog\Http\Client;

/**
 * Describe an in-memory file attached to an HTTP client request.
 */
final readonly class AttachedFileInfo
{
    /**
     * Store attached file metadata.
     */
    public function __construct(
        public string $name,
        public int $size,
        public ?string $mime = null,
    ) {}

    /**
     * Get the file extension from its supplied name.
     */
    public function extension(): string
    {
        return pathinfo($this->name, PATHINFO_EXTENSION);
    }
}
