<?php

namespace Caixingyue\LaravelStarLog\Support;

use Caixingyue\LaravelStarLog\Http\Client\AttachedFileInfo;
use JsonException;
use JsonSerializable;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SplFileInfo;
use Stringable;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * Normalize HTTP request and response data for safe log output.
 */
final readonly class HttpLogDataNormalizer
{
    private SensitiveFieldRules $fieldRules;

    /**
     * Configure field masking and output limits.
     */
    public function __construct(
        private array $sensitiveFields,
        private array $limits = [],
        ?SensitiveFieldRules $fieldRules = null,
    ) {
        $this->fieldRules = $fieldRules ?? new SensitiveFieldRules($sensitiveFields);
    }

    /**
     * Create a data normalizer from one HTTP logging configuration section.
     */
    public static function fromConfig(mixed $config): self
    {
        $config = is_array($config) ? $config : [];
        $sensitiveFields = $config['sensitive_fields'] ?? [];
        $limits = $config['limits'] ?? [];

        return new self(
            is_array($sensitiveFields) ? $sensitiveFields : [],
            is_array($limits) ? $limits : [],
            ($config['_sensitive_field_rules'] ?? null) instanceof SensitiveFieldRules ? $config['_sensitive_field_rules'] : null,
        );
    }

    /**
     * Redact configured credentials and query parameters from a URL.
     */
    public function redactUrl(string $url): string
    {
        return Redactor::redactUrl($url, $this->sensitiveFields, $this->fieldRules);
    }

    /**
     * Prepare a log URL, omitting its query when disabled and masking credentials.
     */
    public function prepareUrl(string $url, bool $includeQuery = true): string
    {
        if (! $includeQuery) {
            $queryPosition = strpos($url, '?');
            $fragmentPosition = strpos($url, '#');

            if ($queryPosition !== false && ($fragmentPosition === false || $queryPosition < $fragmentPosition)) {
                $url = substr($url, 0, $queryPosition)
                    . ($fragmentPosition === false ? '' : substr($url, $fragmentPosition));
            }
        }

        return $this->redactUrl($url);
    }

    /**
     * Redact and normalize a value before it is written to an HTTP log.
     *
     * @throws RuntimeException
     */
    public function prepare(mixed $value): mixed
    {
        return $this->normalizeValue($value, 0);
    }

    /**
     * Select configured headers for logging, then apply masking and output limits.
     * Names match exactly without regard to casing; no matching headers returns null.
     */
    public function prepareHeaders(array $headers, array $allowedHeaders): ?array
    {
        $allowed = [];

        foreach ($allowedHeaders as $name) {
            if (is_string($name) && trim($name) !== '') {
                $allowed[strtolower(trim($name))] = true;
            }
        }

        $headers = array_filter(
            $headers,
            static fn (string $name): bool => isset($allowed[strtolower($name)]),
            ARRAY_FILTER_USE_KEY,
        );

        return $headers === [] ? null : $this->prepare($headers);
    }

    /**
     * Read text within the configured body length, keeping structured bodies complete.
     *
     * @throws RuntimeException
     */
    public function readBody(StreamInterface $stream, ?string $contentType): ?string
    {
        $contentType = HttpContentType::normalizeContentType($contentType ?? '');
        $textOnly = HttpContentType::isTextContentType($contentType)
            && ! str_contains($contentType ?? '', 'json')
            && ! in_array($contentType, ['text/html', 'application/xhtml+xml', 'application/x-www-form-urlencoded'], true);

        $body = HttpBodyReader::read($stream, $textOnly ? $this->limit('max_body_length') : null);
        $prefix = ltrim($body ?? '');

        // JSON can arrive with a text media type; keep its field structure intact.
        return $textOnly && (str_starts_with($prefix, '{') || str_starts_with($prefix, '['))
            ? HttpBodyReader::read($stream)
            : $body;
    }

    /**
     * Truncate one complete request or response body.
     */
    public function truncateBody(string $value): string
    {
        return $this->truncate($value, $this->limit('max_body_length'));
    }

    /**
     * Describe one body with a consistent type and optional media type.
     */
    public function describeBody(string $type, ?string $contentType = null, array $details = []): array
    {
        $body = ['type' => $type];
        $contentType = HttpContentType::normalizeContentType($contentType ?? '');

        if ($contentType !== null) {
            $body['content_type'] = $contentType;
        }

        return array_replace($body, $details);
    }

    /**
     * Describe a body that contains log-safe data.
     *
     * @throws RuntimeException
     */
    public function describeDataBody(string $type, mixed $data, ?string $contentType = null): array
    {
        return $this->describeBody($type, $contentType, ['data' => $this->prepare($data)]);
    }

    /**
     * Describe a text body after applying its complete-body limit.
     */
    public function describeTextBody(string $value, ?string $contentType = null): array
    {
        return $this->describeBody('text', $contentType, ['data' => $this->truncateBody($this->prepare($value))]);
    }

    /**
     * Decode JSON, retaining malformed contents as bounded text.
     */
    public function describeJsonBody(string $value, ?string $contentType = null): array
    {
        try {
            return $this->describeDataBody('json', json_decode($value, true, 512, JSON_THROW_ON_ERROR), $contentType);
        } catch (JsonException) {
            return $this->describeTextBody($value, $contentType);
        }
    }

    /**
     * Describe HTML with a bounded document summary, excluding the raw markup.
     */
    public function describeHtmlBody(string $value, ?string $contentType = null): array
    {
        $details = ['length' => strlen($value)];
        $summary = (new HtmlLogSummaryExtractor(
            $this->limit('max_string_length'),
        ))->extract($value);

        if ($summary !== null) {
            $details['summary'] = $this->prepare($summary);
        }

        return $this->describeBody('html', $contentType, $details);
    }

    /**
     * Describe a client file for HTTP log output.
     *
     * @return array<class-string, array{name: string, extension: string, mime: string|null, size: string|null}>
     *
     * @throws RuntimeException
     */
    public function describeFile(SplFileInfo $file, ?string $name = null): array
    {
        $size = $file->getSize();
        $path = $file->getRealPath();

        $mime = is_string($path) && function_exists('mime_content_type') ? mime_content_type($path) : null;
        $name ??= $file->getFilename();

        return [$file::class => [
            'name' => $name,
            'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'mime' => is_string($mime) ? $mime : null,
            'size' => is_int($size) ? FileSize::format($size) : null,
        ]];
    }

    /**
     * Describe an in-memory file attached by the HTTP client.
     *
     * @return array<class-string<AttachedFileInfo>, array{name: string, extension: string, mime: string|null, size: string}>
     */
    public function describeAttachedFile(AttachedFileInfo $file): array
    {
        return [$file::class => [
            'name' => $file->name,
            'extension' => $file->extension(),
            'mime' => $file->mime,
            'size' => FileSize::format($file->size),
        ]];
    }

    /**
     * Describe an attached PSR stream without reading it or exposing its URI.
     */
    public function describeStreamFile(StreamInterface $stream, ?string $name, ?string $mime = null): array
    {
        $size = $stream->getSize();

        return [$stream::class => [
            'name' => $name,
            'extension' => $name === null ? null : pathinfo($name, PATHINFO_EXTENSION),
            'mime' => $mime,
            'size' => $size === null ? null : FileSize::format($size),
        ]];
    }

    /**
     * Convert a value into a bounded log-safe representation.
     *
     * @throws RuntimeException
     */
    private function normalizeValue(mixed $value, int $depth, string $path = ''): mixed
    {
        if ($depth >= min($this->limit('max_depth') ?? 64, 64)) {
            return '[maximum depth reached]';
        }

        if ($value instanceof UploadedFile) {
            $description = $this->describeUploadedFile($value);

            foreach ($description[$value::class] as $key => $item) {
                $description[$value::class][$key] = $this->normalizeValue($item, 0);
            }

            return $description;
        }

        if ($value instanceof StreamInterface) {
            return '[stream]';
        }

        if (is_array($value)) {
            return $this->normalizeArray($value, $depth, $path);
        }

        if (is_string($value)) {
            if ($path !== '' && $this->fieldRules->isSensitive($path)) {
                return $this->expandMaskedBranch($value, $path)
                    ? $this->truncate($this->redactJsonString($value, $depth, $path), $this->limit('max_string_length'))
                    : Redactor::MASK;
            }

            return $value === Redactor::MASK
                ? $value
                : $this->truncate(
                    Redactor::redactFormData($this->redactJsonString($value, $depth, $path), $this->sensitiveFields, $path, $this->fieldRules),
                    $this->limit('max_string_length'),
                );
        }

        if ($value instanceof JsonSerializable) {
            try {
                return $this->normalizeValue($value->jsonSerialize(), $depth + 1, $path);
            } catch (Throwable) {
                return '[unserializable ' . $value::class . ']';
            }
        }

        if ($value instanceof Stringable) {
            try {
                return $this->normalizeValue((string) $value, $depth + 1, $path);
            } catch (Throwable) {
                return '[unstringable ' . $value::class . ']';
            }
        }

        return is_object($value) ? '[' . $value::class . ']' : (is_resource($value) ? '[resource]' : $value);
    }

    /**
     * Convert a bounded array into a log-safe representation.
     *
     * @throws RuntimeException
     */
    private function normalizeArray(array $value, int $depth, string $path): array
    {
        $maximumItems = $this->limit('max_array_items');

        $items = [];
        $position = 0;

        foreach ($value as $key => $item) {
            if ($maximumItems !== null && $position >= $maximumItems) {
                $items['…'] = sprintf('%d item(s) omitted', count($value) - $position);

                break;
            }

            $logKey = is_string($key) ? $this->truncate($key, 1024) : $key;

            if ($logKey !== $key) {
                $logKey .= '#' . $position;
            }

            $childPath = ($path === '' ? '' : $path . '.') . Redactor::normalizeFieldPath((string) $key);

            if ($this->fieldRules->isSensitive($childPath) && ! $this->expandMaskedBranch($item, $childPath)) {
                $items[$logKey] = Redactor::MASK;
            } else {
                $items[$logKey] = $this->normalizeValue($item, $depth + 1, $childPath);
            }
            $position++;
        }

        return $items;
    }

    /**
     * Keep a branch structured only when a temporary rule exposes one of its children.
     * Opaque strings stay masked because they cannot supply a verified child field.
     */
    private function expandMaskedBranch(mixed $value, string $path): bool
    {
        if (! $this->fieldRules->hasVisibleDescendant($path)) {
            return false;
        }

        if (is_array($value) || $value instanceof JsonSerializable) {
            return true;
        }

        if (is_string($value)) {
            try {
                return is_array(json_decode($value, true, 512, JSON_THROW_ON_ERROR));
            } catch (JsonException) {
                return false;
            }
        }

        return false;
    }

    /**
     * Redact fields embedded in a JSON object or array stored as a string.
     */
    private function redactJsonString(string $value, int $depth, string $path): string
    {
        $json = ltrim($value);

        if (! str_starts_with($json, '{') && ! str_starts_with($json, '[')) {
            return $value;
        }

        try {
            $data = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($data)) {
                return $value;
            }

            return json_encode(
                $this->normalizeValue($data, $depth + 1, $path),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return $value;
        }
    }

    /**
     * Describe an uploaded file for HTTP log output.
     *
     * @return array<class-string, array{name: string, extension: string, mime: string|null, size: string|null}>
     *
     * @throws RuntimeException
     */
    private function describeUploadedFile(UploadedFile $file): array
    {
        $size = $file->getSize();

        return [$file::class => [
            'name' => $file->getClientOriginalName(),
            'extension' => $file->getClientOriginalExtension(),
            'mime' => $file->getClientMimeType(),
            'size' => is_int($size) ? FileSize::format($size) : null,
        ]];
    }

    /**
     * Truncate a string while preserving an explicit marker in the log.
     */
    private function truncate(string $value, ?int $maximumLength): string
    {
        if ($maximumLength === null || mb_strlen($value) <= $maximumLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maximumLength)) . '…';
    }

    /**
     * Resolve a configured positive HTTP log limit.
     */
    private function limit(string $name): ?int
    {
        $value = $this->limits[$name] ?? null;

        return is_int($value) && $value > 0 ? $value : null;
    }
}
