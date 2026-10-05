<?php

namespace Caixingyue\LaravelStarLog\Support;

/**
 * Resolve HTTP masking decisions from configured paths and nested temporary rules.
 * A matching inner scope takes precedence.
 */
final readonly class SensitiveFieldRules
{
    /**
     * Configure sensitive field paths and temporary masking overrides.
     *
     * @param  array<int, array{fields: array, sensitive: bool}>  $overrides
     */
    public function __construct(private array $fields, private array $overrides = []) {}

    /**
     * Determine whether a field path should be masked, applying inner overrides first.
     */
    public function isSensitive(string $path): bool
    {
        $segments = explode('.', Redactor::normalizeFieldPath($path));

        foreach (array_reverse($this->overrides) as $rule) {
            foreach ($rule['fields'] as $field) {
                if (is_string($field) && $this->matches(explode('.', Redactor::normalizeFieldPath($field)), $segments)) {
                    return $rule['sensitive'];
                }
            }
        }

        foreach ($this->fields as $field) {
            if (is_string($field) && $this->matches(explode('.', Redactor::normalizeFieldPath($field)), $segments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a masked branch must be expanded to expose a selected child.
     * Leaf decisions still apply all overrides, including inner masking rules.
     */
    public function hasVisibleDescendant(string $path): bool
    {
        $segments = explode('.', Redactor::normalizeFieldPath($path));

        foreach ($this->overrides as $rule) {
            if ($rule['sensitive']) {
                continue;
            }

            foreach ($rule['fields'] as $field) {
                if (! is_string($field)) {
                    continue;
                }

                $pattern = explode('.', Redactor::normalizeFieldPath($field));

                if (count($pattern) > count($segments) && $this->matches(array_slice($pattern, 0, count($segments)), $segments)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Match a path or branch prefix; each wildcard matches one path segment.
     */
    private function matches(array $pattern, array $segments): bool
    {
        if ($pattern === [''] || count($pattern) > count($segments)) {
            return false;
        }

        foreach ($pattern as $index => $part) {
            if ($part !== '*' && $part !== $segments[$index]) {
                return false;
            }
        }

        return true;
    }
}
