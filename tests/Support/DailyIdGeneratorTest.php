<?php

namespace Caixingyue\LaravelStarLog\Tests\Support;

use Caixingyue\LaravelStarLog\Support\DailyIdGenerator;
use DateTimeImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository;
use OverflowException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DailyIdGeneratorTest extends TestCase
{
    public function test_generates_unique_thirteen_digit_ids_with_time_at_fixed_positions(): void
    {
        $cache = new Repository(new ArrayStore);
        $clock = fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-23 23:59:59');
        $firstGenerator = new DailyIdGenerator($cache, $clock, 'test-key');
        $secondGenerator = new DailyIdGenerator($cache, $clock, 'test-key');

        $firstId = $firstGenerator->generate();
        $secondId = $secondGenerator->generate();

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame('235959', $this->extractTime($firstId));
        $this->assertSame('235959', $this->extractTime($secondId));
        $this->assertMatchesRegularExpression('/^[1-9]\d{12}$/', (string) $firstId);
        $this->assertSame(2, $cache->get('laravel-star-log:id:2026-09-23'));
    }

    public function test_starts_a_new_sequence_on_the_next_application_date(): void
    {
        $cache = new Repository(new ArrayStore);
        $date = new DateTimeImmutable('2026-09-23 23:59:59');
        $generator = new DailyIdGenerator($cache, function () use (&$date): DateTimeImmutable {
            return $date;
        });

        $firstId = $generator->generate();

        $date = new DateTimeImmutable('2026-09-24 00:00:00');

        $secondId = $generator->generate();

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame('000000', $this->extractTime($secondId));
    }

    public function test_generates_unique_ids_for_a_large_same_second_batch(): void
    {
        $cache = new Repository(new ArrayStore);
        $generator = new DailyIdGenerator(
            $cache,
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-23 10:20:12'),
            'test-key'
        );

        $ids = [];

        for ($index = 0; $index < 1_000; $index++) {
            $ids[] = $generator->generate();
        }

        $this->assertCount(1_000, array_unique($ids));
        $this->assertSame(1_000, $cache->get('laravel-star-log:id:2026-09-23'));
    }

    public function test_rejects_a_cache_store_that_cannot_persist_the_counter(): void
    {
        $generator = new DailyIdGenerator(
            new Repository(new NullStore),
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-23 10:20:12')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot atomically increment');

        $generator->generate();
    }

    public function test_rejects_a_sequence_that_exceeds_the_daily_capacity(): void
    {
        $cache = new Repository(new ArrayStore);
        $cache->put('laravel-star-log:id:2026-09-23', 9_000_000, 60);
        $generator = new DailyIdGenerator(
            $cache,
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-23 10:20:12')
        );

        $this->expectException(OverflowException::class);

        $generator->generate();
    }

    private function extractTime(int $id): string
    {
        $id = (string) $id;

        return substr($id, 1, 2) . substr($id, 5, 2) . substr($id, 9, 2);
    }
}
