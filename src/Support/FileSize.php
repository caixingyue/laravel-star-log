<?php

namespace Caixingyue\LaravelStarLog\Support;

final class FileSize
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /**
     * Format a byte count with a human-readable unit.
     */
    public static function format(int|float|null $bytes, int $precision = 2): string
    {
        $size = max(0.0, (float) ($bytes ?? 0));

        $unitIndex = 0;
        $lastUnitIndex = count(self::UNITS) - 1;

        while ($size >= 1024 && $unitIndex < $lastUnitIndex) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, max(0, $precision)) . self::UNITS[$unitIndex];
    }
}
