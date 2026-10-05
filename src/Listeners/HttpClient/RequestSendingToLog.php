<?php

namespace Caixingyue\LaravelStarLog\Listeners\HttpClient;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Http\Client\AttachedFileInfo;
use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogSnapshots;
use Caixingyue\LaravelStarLog\Support\HttpContentType;
use Caixingyue\LaravelStarLog\Support\HttpLogDataNormalizer;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use SplFileInfo;
use Throwable;

/**
 * Record an outgoing HTTP client request.
 */
final class RequestSendingToLog
{
    /**
     * Record the request before Laravel's HTTP client sends it.
     */
    public function handle(RequestSending $event): void
    {
        try {
            $request = $event->request;
            $config = app(HttpClientLogSnapshots::class)->forRequest($request->toPsrRequest())->config;

            if (($config['enable'] ?? false) !== true || ($config['request']['enable'] ?? true) !== true) {
                return;
            }

            $normalizer = HttpLogDataNormalizer::fromConfig($config);

            $message = StarLog::translate('client.request', [
                'method' => $request->method(),
                'url' => $normalizer->prepareUrl($request->url(), ($config['request']['query'] ?? true) === true),
            ]);

            $context = array_filter([
                'headers' => $normalizer->prepareHeaders($request->headers(), $config['request']['headers'] ?? []),
                'body' => $this->describeRequestBody($request, $normalizer, $config),
            ], static fn (mixed $value): bool => $value !== null);

            Log::info($message, $context);
        } catch (Throwable) {
            // HTTP client logging must never affect the request being sent.
        }
    }

    /**
     * Build a safe body description for an outgoing HTTP client request.
     */
    private function describeRequestBody(Request $request, HttpLogDataNormalizer $normalizer, array $config): ?array
    {
        if (($config['request']['body'] ?? true) !== true) {
            return null;
        }

        $psrRequest = $request->toPsrRequest();
        $contentType = HttpContentType::normalizeContentType($psrRequest->getHeaderLine('Content-Type'));

        if ($contentType === 'multipart/form-data') {
            return $normalizer->describeDataBody('multipart', $this->multipartData($request, $normalizer), $contentType);
        }

        if (HttpContentType::isBinaryContentType($contentType)) {
            return $normalizer->describeBody('binary', $contentType);
        }

        $body = $normalizer->readBody($psrRequest->getBody(), $contentType);

        if ($body === null) {
            return $normalizer->describeBody('stream', $contentType);
        }

        if ($body === '') {
            return $normalizer->describeBody('empty');
        }

        if (Str::isJson($body) || str_contains($contentType ?? '', 'json')) {
            return $normalizer->describeJsonBody($body, $contentType);
        }

        if ($contentType === 'application/x-www-form-urlencoded') {
            parse_str($body, $data);

            return $normalizer->describeDataBody('form', $data, $contentType);
        }

        if (in_array($contentType, ['text/html', 'application/xhtml+xml'], true)) {
            return $normalizer->describeHtmlBody($body, $contentType);
        }

        return HttpContentType::isTextContentType($contentType)
            ? $normalizer->describeTextBody($body, $contentType)
            : $normalizer->describeBody('unknown', $contentType);
    }

    /**
     * Convert multipart request items into log-safe data without reading file contents.
     */
    private function multipartData(Request $request, HttpLogDataNormalizer $normalizer): array
    {
        $parts = $request->data();
        $multipartData = [];

        foreach ($parts as $key => $item) {
            $fieldName = $key;
            $contents = $item;
            $filename = null;

            if (is_array($item)) {
                $fieldName = Arr::get($item, 'name', $key);
                $contents = Arr::get($item, 'contents', $item);
                $filename = Arr::get($item, 'filename');
            }

            if (is_string($contents) && is_string($filename) && $filename !== '') {
                $multipartData[$fieldName][] = $normalizer->describeAttachedFile(
                    new AttachedFileInfo(
                        $filename,
                        strlen($contents),
                        $this->multipartContentType($item),
                    ),
                );

                continue;
            }

            if ($contents instanceof StreamInterface) {
                $multipartData[$fieldName][] = $normalizer->describeStreamFile(
                    $contents,
                    is_string($filename) && $filename !== '' ? $filename : null,
                    $this->multipartContentType($item),
                );

                continue;
            }

            if (is_resource($contents)) {
                $meta = stream_get_meta_data($contents);
                $path = $meta['uri'] ?? null;

                if (! is_string($path) || ! is_file($path)) {
                    $multipartData[$fieldName][] = '[stream resource]';

                    continue;
                }

                $fileInfo = new SplFileInfo($path);
                $multipartData[$fieldName][] = $normalizer->describeFile(
                    $fileInfo,
                    is_string($filename) && $filename !== '' ? $filename : null,
                );

                continue;
            }

            $multipartData[$fieldName][] = $contents;
        }

        return array_map(static fn (array $items): mixed => count($items) === 1 ? $items[0] : $items, $multipartData);
    }

    /**
     * Get the declared content type for one multipart item.
     */
    private function multipartContentType(mixed $item): ?string
    {
        $headers = Arr::get($item, 'headers');

        if (! is_array($headers)) {
            return null;
        }

        foreach ($headers as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'Content-Type') === 0 && is_string($value)) {
                return $value;
            }
        }

        return null;
    }
}
