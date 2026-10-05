<?php

namespace Caixingyue\LaravelStarLog\Tests\Support;

use Caixingyue\LaravelStarLog\Support\FileSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileSizeTest extends TestCase
{
    #[DataProvider('sizes')]
    public function test_it_formats_file_sizes(int|float|null $bytes, string $expected): void
    {
        $this->assertSame($expected, FileSize::format($bytes));
    }

    public static function sizes(): array
    {
        return [
            [null, '0B'],
            [-1, '0B'],
            [0, '0B'],
            [0.5, '0.5B'],
            [1, '1B'],
            [1023, '1023B'],
            [1024, '1KB'],
            [1536, '1.5KB'],
            [1024 ** 2, '1MB'],
            [1024 ** 5, '1PB'],
        ];
    }

    public function test_it_uses_the_requested_precision(): void
    {
        $this->assertSame('1.205KB', FileSize::format(1234, 3));
    }
}
