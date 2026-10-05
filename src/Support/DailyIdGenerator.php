<?php

namespace Caixingyue\LaravelStarLog\Support;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use OverflowException;
use RuntimeException;

final readonly class DailyIdGenerator
{
    private const MAX_SEQUENCE = 9_000_000;

    private const RANDOMIZED_SEQUENCE_START = 1_000_000;

    private const RANDOMIZED_SEQUENCE_RANGE = 9_000_000;

    private const RANDOMIZED_SEQUENCE_MULTIPLIER = 1_103_515_247;

    /**
     * Create a generator backed by a shared atomic cache counter.
     *
     * @param  Repository  $cache  Stores the daily sequence shared by all application workers.
     * @param  (Closure(): DateTimeImmutable)|null  $clock  Provides the current application time for deterministic tests.
     * @param  string  $randomizationKey  Seeds the daily sequence permutation.
     */
    public function __construct(
        private Repository $cache,
        private ?Closure $clock = null,
        private string $randomizationKey = ''
    ) {}

    /**
     * Generate an ID with randomized digits interspersed with the application time.
     *
     * @throws RuntimeException
     * @throws OverflowException
     * @throws LockTimeoutException
     */
    public function generate(): int
    {
        if (PHP_INT_SIZE < 8) {
            throw new RuntimeException('Time-based daily log IDs require a 64-bit PHP build.');
        }

        $now = $this->now();
        $date = $now->format('Y-m-d');
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The configured cache store does not support atomic locks for daily log IDs.');
        }

        $key = "laravel-star-log:id:{$date}";
        $sequence = $store->lock("{$key}:lock", 10)->block(5, function () use ($key, $now): int|bool {
            $expiresAt = $now->setTime(0, 0)->modify('+2 days');

            $this->cache->add($key, 0, $expiresAt);

            return $this->cache->increment($key);
        });

        if (! is_int($sequence) || $sequence < 1) {
            throw new RuntimeException('The configured cache store cannot atomically increment the daily log ID.');
        }

        if ($sequence > self::MAX_SEQUENCE) {
            throw new OverflowException("The daily log ID sequence for [{$date}] has been exhausted.");
        }

        return $this->format($now, $date, $sequence);
    }

    /**
     * Get the current time in the application's configured timezone.
     */
    private function now(): DateTimeImmutable
    {
        return $this->clock === null ? new DateTimeImmutable('now') : ($this->clock)();
    }

    /**
     * Format the daily sequence without exposing its sequential value.
     *
     * The seven sequence digits remain intact, so the fixed time positions do
     * not affect uniqueness. The first randomized digit is never zero.
     */
    private function format(DateTimeImmutable $now, string $date, int $sequence): int
    {
        $randomizedSequence = (string) ($this->randomizedSequence($date, $sequence));
        $time = $now->format('His');

        return (int) (
            substr($randomizedSequence, 0, 1)
            . substr($time, 0, 2)
            . substr($randomizedSequence, 1, 2)
            . substr($time, 2, 2)
            . substr($randomizedSequence, 3, 2)
            . substr($time, 4, 2)
            . substr($randomizedSequence, 5, 2)
        );
    }

    /**
     * Map the daily sequence to a different seven-digit value for each date.
     */
    private function randomizedSequence(string $date, int $sequence): int
    {
        $offset = unpack(
            'Noffset',
            substr(hash_hmac('sha256', $date, $this->randomizationKey ?: self::class, true), 0, 4)
        )['offset'] % self::RANDOMIZED_SEQUENCE_RANGE;

        return (((($sequence - 1) * self::RANDOMIZED_SEQUENCE_MULTIPLIER) + $offset)
            % self::RANDOMIZED_SEQUENCE_RANGE) + self::RANDOMIZED_SEQUENCE_START;
    }
}
