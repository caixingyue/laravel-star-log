<?php

namespace Caixingyue\LaravelStarLog\Support;

/**
 * Merge temporary log options without merging positional lists by index.
 */
final class LogOptions
{
    /**
     * Merge nested option maps, replacing lists and empty arrays as complete values.
     */
    public static function merge(array $base, array $options): array
    {
        foreach ($options as $key => $value) {
            if (is_array($value) && $value !== [] && ! array_is_list($value) && is_array($base[$key] ?? null)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
