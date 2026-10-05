<?php

namespace Caixingyue\LaravelStarLog\Query;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Stringable;

final class QueryBindingFormatter
{
    private const MASK = '******';

    /**
     * Format bindings only after a query has passed every logging filter.
     */
    public function format(array $bindings, SqlStatement $statement, array $config, QueryBindingColumnRegistry $registry, array $sensitiveRules = []): array|string
    {
        if (($config['enable'] ?? false) !== true) {
            return '[hidden]';
        }

        $limit = $this->nullablePositiveInteger($config['max_count'] ?? null);
        $resolvedColumns = $this->resolveColumns($config['columns'] ?? [], $statement, $registry);

        $formatted = [];
        $position = 0;
        $remaining = 1000;

        foreach ($bindings as $key => $value) {
            if ($remaining <= 0) {
                $formatted['…'] = '[maximum item count reached]';

                break;
            }

            if ($limit !== null && $position >= $limit) {
                $formatted['…'] = sprintf('%d binding(s) omitted', count($bindings) - $position);

                break;
            }

            $bindingName = is_string($key) ? ltrim($key, ':') : null;
            $columnName = $statement->bindingColumns[$bindingName ?? $key] ?? null;

            $columnOptions = $this->optionsForColumn($columnName, $resolvedColumns);

            if ($columnName !== null) {
                foreach ($sensitiveRules as $rule) {
                    $decision = $rule['*'][$columnName] ?? null;
                    $matchedLength = 0;

                    foreach ($rule as $table => $columns) {
                        if ($table !== '*' && $statement->targetsPrimaryTable($table)
                            && array_key_exists($columnName, $columns) && strlen($table) >= $matchedLength) {
                            $decision = $columns[$columnName];
                            $matchedLength = strlen($table);
                        }
                    }

                    if (is_bool($decision)) {
                        $columnOptions['sensitive'] = $decision;
                    }
                }
            }

            $remaining--;

            $formatted[$key] = ($columnOptions['sensitive'] ?? false) === true ? self::MASK
                : $this->formatValue($value, $this->resolveMaximumLength($columnOptions, $config), 0, $remaining);

            $position++;
        }

        return $formatted;
    }

    /**
     * Merge binding column settings from global defaults, table settings, and models, in that order.
     *
     * @return array<string, array{sensitive?: bool, max_length?: int|null}>
     */
    private function resolveColumns(mixed $configuredColumns, SqlStatement $statement, QueryBindingColumnRegistry $registry): array
    {
        $configuredColumns = is_array($configuredColumns) ? $configuredColumns : [];
        $globalColumns = $this->normalizeColumns($configuredColumns['*'] ?? []);
        $tableColumns = $this->normalizeColumns(is_null($statement->table) ? [] : ($configuredColumns[$statement->table] ?? []));
        $modelColumns = $this->normalizeColumns($registry->forTable($statement->table));

        return array_replace_recursive($globalColumns, $tableColumns, $modelColumns);
    }

    /**
     * Normalize column names for case-insensitive settings lookup.
     *
     * @return array<string, array{sensitive?: bool, max_length?: int|null}>
     */
    private function normalizeColumns(mixed $columns): array
    {
        if (! is_array($columns)) {
            return [];
        }

        $normalizedColumns = [];

        foreach ($columns as $columnName => $columnOptions) {
            if (! is_string($columnName) || $columnName === '' || ! is_array($columnOptions)) {
                continue;
            }

            $normalizedColumns[strtolower($columnName)] = $columnOptions;
        }

        return $normalizedColumns;
    }

    /**
     * Get the log settings for one verified binding column.
     *
     * @param  array<string, array{sensitive?: bool, max_length?: int|null}>  $resolvedColumns
     * @return array{sensitive?: bool, max_length?: int|null}
     */
    private function optionsForColumn(?string $columnName, array $resolvedColumns): array
    {
        if (is_null($columnName)) {
            return [];
        }

        return $resolvedColumns[strtolower($columnName)] ?? [];
    }

    /**
     * Resolve a column-specific length limit or the default binding limit.
     *
     * @param  array{sensitive?: bool, max_length?: int|null}  $columnOptions
     */
    private function resolveMaximumLength(array $columnOptions, array $config): ?int
    {
        if (array_key_exists('max_length', $columnOptions) && is_null($columnOptions['max_length'])) {
            return null;
        }

        return $this->nullablePositiveInteger($columnOptions['max_length'] ?? null)
            ?? $this->nullablePositiveInteger($config['max_length'] ?? null);
    }

    /**
     * Convert a binding value into a safe, loggable representation.
     */
    private function formatValue(mixed $value, ?int $maximumLength, int $depth, int &$remaining): mixed
    {
        if (--$remaining < 0) {
            return '[maximum item count reached]';
        }

        if ($depth >= 8) {
            return '[maximum depth reached]';
        }

        return match (true) {
            is_string($value) => $this->formatString($value, $maximumLength),
            is_resource($value) => '[resource]',
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof Arrayable => $this->formatValue($value->toArray(), $maximumLength, $depth + 1, $remaining),
            $value instanceof Jsonable => $this->formatString($value->toJson(), $maximumLength),
            $value instanceof Stringable => $this->formatString((string) $value, $maximumLength),
            is_array($value) => $this->formatArray($value, $maximumLength, $depth, $remaining),
            is_object($value) => '[' . $value::class . ']',
            default => $value,
        };
    }

    /**
     * Format array bindings within item and nesting limits, preserving keys and marking omissions.
     */
    private function formatArray(array $value, ?int $maximumLength, int $depth, int &$remaining): array
    {
        $result = [];

        foreach (array_slice($value, 0, 50, true) as $key => $item) {
            if ($remaining <= 0) {
                $result['…'] = '[maximum item count reached]';

                break;
            }

            $result[$key] = $this->formatValue($item, $maximumLength, $depth + 1, $remaining);
        }

        if (count($value) > 50) {
            $result['…'] = sprintf('%d item(s) omitted', count($value) - 50);
        }

        return $result;
    }

    /**
     * Convert text to a safe log value without writing binary data verbatim.
     */
    private function formatString(string $value, ?int $maximumLength): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return '[binary: ' . strlen($value) . ' bytes]';
        }

        return $this->truncate($value, $maximumLength);
    }

    /**
     * Shorten text while keeping the truncation visible in the log.
     */
    private function truncate(string $value, ?int $maximumLength): string
    {
        if ($maximumLength === null || mb_strlen($value) <= $maximumLength) {
            return $value;
        }

        return mb_substr($value, 0, $maximumLength) . '…';
    }

    /**
     * Treat only positive integer configuration values as limits.
     */
    private function nullablePositiveInteger(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
