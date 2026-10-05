<?php

namespace Caixingyue\LaravelStarLog\Support;

final class Redactor
{
    /**
     * The value used to replace sensitive data.
     */
    public const MASK = '******';

    /**
     * Recursively redact sensitive fields from a value.
     */
    public static function redact(mixed $value, array $sensitiveFields): mixed
    {
        $remaining = 10000;

        return self::redactValue($value, self::createFieldLookup($sensitiveFields), 0, $remaining, '');
    }

    /**
     * Determine whether a field name is configured as sensitive.
     */
    public static function isSensitive(string $field, array $sensitiveFields, ?string $path = null): bool
    {
        return self::isSensitiveField($field, self::createFieldLookup($sensitiveFields), $path);
    }

    /**
     * Convert bracket notation to a lowercase dot path for field matching.
     */
    public static function normalizeFieldPath(string $path): string
    {
        return mb_strtolower(trim(str_replace(['[', ']'], ['.', ''], $path), '.'));
    }

    /**
     * Mask configured URL credentials and query values while preserving their structure.
     */
    public static function redactUrl(string $url, array $sensitiveFields, ?SensitiveFieldRules $rules = null): string
    {
        $url = self::redactUrlCredentials($url, $sensitiveFields, $rules);
        $fragmentPosition = strpos($url, '#');
        $queryPosition = strpos($url, '?');

        if ($queryPosition === false || ($fragmentPosition !== false && $queryPosition > $fragmentPosition)) {
            return $url;
        }

        $queryEndPosition = $fragmentPosition === false ? strlen($url) : $fragmentPosition;
        $queryString = substr($url, $queryPosition + 1, $queryEndPosition - $queryPosition - 1);

        return substr($url, 0, $queryPosition + 1)
            . self::redactFormData($queryString, $sensitiveFields, '', $rules)
            . substr($url, $queryEndPosition);
    }

    /**
     * Mask the username and password in URL credentials according to configuration.
     */
    private static function redactUrlCredentials(string $url, array $sensitiveFields, ?SensitiveFieldRules $rules): string
    {
        return preg_replace_callback('~^((?:[a-z][a-z0-9+.-]*:)?//)([^/?#]*)@~i', static function (array $matches) use ($sensitiveFields, $rules): string {
            $credentials = explode(':', $matches[2], 2);

            if ($credentials[0] !== '' && ($rules?->isSensitive('username') ?? self::isSensitive('username', $sensitiveFields))) {
                $credentials[0] = self::MASK;
            }

            if (isset($credentials[1]) && $credentials[1] !== '' && ($rules?->isSensitive('password') ?? self::isSensitive('password', $sensitiveFields))) {
                $credentials[1] = self::MASK;
            }

            return $matches[1] . implode(':', $credentials) . '@';
        }, $url) ?? $url;
    }

    /**
     * Mask configured sensitive fields in URL-encoded form data.
     */
    public static function redactFormData(string $data, array $sensitiveFields, string $parentPath = '', ?SensitiveFieldRules $rules = null): string
    {
        $fieldLookup = self::createFieldLookup($sensitiveFields);
        $parameters = explode('&', $data);

        foreach ($parameters as $index => $parameter) {
            [$field] = explode('=', $parameter, 2);

            $decodedField = urldecode($field);
            // PHP truncates NULs, strips leading spaces and mangles top-level names.
            // Check both interpretations without rebuilding or collapsing query parameters.
            parse_str($field . '=1', $parsedField);
            $phpSegments = [];

            while (is_array($parsedField) && $parsedField !== [] && count($phpSegments) < 64) {
                $key = array_key_first($parsedField);
                $phpSegments[] = (string) $key;
                $parsedField = $parsedField[$key];
            }

            $wirePath = ($parentPath === '' ? '' : $parentPath . '.') . self::normalizeFieldPath($decodedField);
            $phpField = implode('.', $phpSegments);
            $phpPath = ($parentPath === '' ? '' : $parentPath . '.') . self::normalizeFieldPath($phpField);
            $wireSensitive = $rules?->isSensitive($wirePath) ?? self::isSensitiveField($decodedField, $fieldLookup, $wirePath);
            $phpSensitive = $rules?->isSensitive($phpPath) ?? self::isSensitiveField($phpField, $fieldLookup, $phpPath);

            if ($wireSensitive || $phpSensitive) {
                $parameters[$index] = $field . '=' . self::MASK;
            }
        }

        return implode('&', $parameters);
    }

    /**
     * Recursively replace values stored under sensitive field names.
     *
     * @param  array<int|string, true>  $fieldLookup
     */
    private static function redactValue(mixed $value, array $fieldLookup, int $depth, int &$remaining, string $path): mixed
    {
        if ($depth >= 64 || --$remaining < 0) {
            return '[redaction limit reached]';
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $field => $item) {
            if ($remaining <= 0) {
                $redacted['…'] = '[redaction limit reached]';

                break;
            }

            $childPath = ($path === '' ? '' : $path . '.') . self::normalizeFieldPath((string) $field);

            if (self::isSensitiveField((string) $field, $fieldLookup, $childPath)) {
                $redacted[$field] = self::MASK;
                $remaining--;
            } else {
                $redacted[$field] = self::redactValue($item, $fieldLookup, $depth + 1, $remaining, $childPath);
            }
        }

        return $redacted;
    }

    /**
     * Create a case-insensitive lookup for configured sensitive field names.
     *
     * @return array<int|string, true>
     */
    private static function createFieldLookup(array $sensitiveFields): array
    {
        $fieldLookup = [];

        foreach ($sensitiveFields as $field) {
            if (is_string($field) && $field !== '') {
                $fieldLookup[self::normalizeFieldPath($field)] = true;
            }
        }

        return $fieldLookup;
    }

    /**
     * Determine whether a field matches the sensitive field rules.
     *
     * @param  array<int|string, true>  $fieldLookup
     */
    private static function isSensitiveField(string $field, array $fieldLookup, ?string $path = null): bool
    {
        $normalizedPath = self::normalizeFieldPath($path ?? $field);

        if (isset($fieldLookup[$normalizedPath])) {
            return true;
        }

        $segments = explode('.', $normalizedPath);

        foreach ($fieldLookup as $pattern => $_) {
            $parts = explode('.', (string) $pattern);

            if (count($parts) > count($segments)) {
                continue;
            }

            foreach ($parts as $index => $part) {
                if ($part !== '*' && $part !== $segments[$index]) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }
}
