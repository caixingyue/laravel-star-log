<?php

namespace Caixingyue\LaravelStarLog\Tests\Support;

use Caixingyue\LaravelStarLog\Support\HttpBodyReader;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

final class HttpBodyReaderTest extends TestCase
{
    public function test_empty_input_streams_are_recognized_after_the_first_read(): void
    {
        $stream = Utils::streamFor(fopen('php://input', 'r'));

        $this->assertSame('', HttpBodyReader::read($stream));
        $this->assertSame(0, $stream->tell());
    }

    public function test_text_prefix_reads_stop_even_when_stream_size_is_unknown(): void
    {
        $stream = Utils::streamFor(str_repeat('x', 1024));
        $stream->seek(7);
        $readBytes = 0;
        $wrapped = FnStream::decorate($stream, [
            'getSize' => static fn () => null,
            'read' => function (int $length) use ($stream, &$readBytes): string {
                $readBytes += $length;

                return $stream->read($length);
            },
        ]);

        $this->assertSame(str_repeat('x', 33), HttpBodyReader::read($wrapped, 32));
        $this->assertSame(7, $stream->tell());
        $this->assertLessThanOrEqual(132, $readBytes);
    }

    public function test_small_streams_are_fully_read_and_their_position_is_restored(): void
    {
        $stream = Utils::streamFor('hello');
        $stream->seek(2);

        $this->assertSame('hello', HttpBodyReader::read($stream, 5));
        $this->assertSame(2, $stream->tell());
    }

    public function test_text_prefixes_preserve_multibyte_characters_across_short_reads(): void
    {
        $stream = Utils::streamFor('你好🌍世界');
        $stream->seek(7);
        $wrapped = FnStream::decorate($stream, [
            'read' => static fn (int $length): string => $stream->read(1),
        ]);

        $this->assertSame('你好🌍', HttpBodyReader::read($wrapped, 2));
        $this->assertSame(7, $stream->tell());
        $this->assertSame('你好🌍世界', HttpBodyReader::read($stream));
        $this->assertSame(7, $stream->tell());
    }

    public function test_position_is_restored_when_reading_throws(): void
    {
        $stream = Utils::streamFor('hello');
        $stream->seek(2);
        $wrapped = FnStream::decorate($stream, [
            'read' => static function (): never {
                throw new \RuntimeException('Read failed.');
            },
        ]);

        try {
            HttpBodyReader::read($wrapped);
            $this->fail('The stream failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Read failed.', $exception->getMessage());
        }

        $this->assertSame(2, $stream->tell());
    }
}
