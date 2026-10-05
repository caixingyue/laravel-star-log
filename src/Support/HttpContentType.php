<?php

namespace Caixingyue\LaravelStarLog\Support;

/**
 * Normalize and classify HTTP media types for logging.
 */
final class HttpContentType
{
    /**
     * Remove media type parameters and normalize whitespace and casing.
     */
    public static function normalizeContentType(string $header): ?string
    {
        $type = strtolower(trim(explode(';', $header, 2)[0]));

        return $type === '' ? null : $type;
    }

    /**
     * Determine whether the normalized media type represents a binary body.
     */
    public static function isBinaryContentType(?string $type): bool
    {
        foreach (['image/', 'audio/', 'video/', 'font/', 'application/vnd.openxmlformats-officedocument'] as $prefix) {
            if (str_starts_with($type ?? '', $prefix)) {
                return true;
            }
        }

        return in_array($type, [
            'application/octet-stream', 'application/pdf', 'application/zip',
            'application/gzip', 'application/x-gzip', 'application/x-tar',
            'application/x-7z-compressed', 'application/x-rar-compressed',
            'application/vnd.rar', 'application/msword', 'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint', 'application/wasm',
        ], true);
    }

    /**
     * Determine whether the normalized media type represents a supported text body.
     */
    public static function isTextContentType(?string $type): bool
    {
        return str_starts_with($type ?? '', 'text/')
            || in_array($type, ['application/xml', 'application/javascript', 'application/x-www-form-urlencoded'], true)
            || str_ends_with($type ?? '', '+xml');
    }
}
