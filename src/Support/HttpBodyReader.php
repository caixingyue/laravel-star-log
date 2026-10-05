<?php

namespace Caixingyue\LaravelStarLog\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Read an HTTP body or text prefix without changing its stream position.
 */
final class HttpBodyReader
{
    /**
     * Read one extra character when limited so output truncation can add its marker.
     * A null result means the stream cannot be inspected without consuming it.
     *
     * @throws RuntimeException
     */
    public static function read(StreamInterface $stream, ?int $maximumLength = null): ?string
    {
        if (! $stream->isReadable() || ! $stream->isSeekable()) {
            return null;
        }

        $position = $stream->tell();

        try {
            $stream->rewind();
            $maximumLength = $maximumLength !== null && $maximumLength > 0 ? $maximumLength : null;
            // A UTF-8 character occupies at most four bytes, including the extra marker character.
            $remainingBytes = $maximumLength === null ? null : min($maximumLength + 1, intdiv(PHP_INT_MAX, 4)) * 4;
            $contents = '';

            while (! $stream->eof() && ($remainingBytes === null || $remainingBytes > 0)) {
                $chunk = $stream->read($remainingBytes === null ? 8192 : min(8192, $remainingBytes));

                if ($chunk === '') {
                    if (! $stream->eof()) {
                        return null;
                    }

                    break;
                }

                $contents .= $chunk;

                if ($remainingBytes !== null) {
                    $remainingBytes -= strlen($chunk);
                }
            }

            return $maximumLength === null ? $contents : mb_substr($contents, 0, $maximumLength + 1);
        } finally {
            $stream->seek($position);
        }
    }
}
