<?php

namespace Caixingyue\LaravelStarLog\Correlation;

use Caixingyue\LaravelStarLog\Support\DailyIdGenerator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use OverflowException;
use RuntimeException;

/**
 * Own the correlation chain for one Star Log execution scope.
 */
final class CorrelationContext
{
    private const CORRELATION_TYPES = ['request', 'artisan', 'queue'];

    private array $correlationChain = [];

    /**
     * Create a correlation context.
     */
    public function __construct(private readonly DailyIdGenerator $idGenerator) {}

    /**
     * Get the complete correlation chain for the active execution context.
     */
    public function getCorrelationChain(): array
    {
        return $this->correlationChain;
    }

    /**
     * Get the correlation item for the currently executing unit.
     */
    public function getCurrentCorrelation(): ?array
    {
        $chain = $this->getCorrelationChain();

        return $chain === [] ? null : $chain[array_key_last($chain)];
    }

    /**
     * Get the correlation item that directly invoked the current unit.
     */
    public function getParentCorrelation(): ?array
    {
        $chain = $this->getCorrelationChain();

        return count($chain) < 2 ? null : $chain[array_key_last($chain) - 1];
    }

    /**
     * Get the request correlation ID.
     */
    public function getRequestId(): ?int
    {
        return $this->findCorrelationId('request');
    }

    /**
     * Get the current or nearest preceding Artisan command correlation ID.
     */
    public function getArtisanId(): ?int
    {
        return $this->findCorrelationId('artisan');
    }

    /**
     * Get the current or nearest preceding queue job correlation ID.
     */
    public function getQueueId(): ?int
    {
        return $this->findCorrelationId('queue');
    }

    /**
     * Get the nearest Artisan correlation ID before the current execution unit.
     */
    public function getNearestArtisanId(): ?int
    {
        return $this->findCorrelationId('artisan', true);
    }

    /**
     * Get the nearest queue correlation ID before the current execution unit.
     */
    public function getNearestQueueId(): ?int
    {
        return $this->findCorrelationId('queue', true);
    }

    /**
     * Get the first and current items for the default log format.
     */
    public function getDisplayCorrelations(): array
    {
        $chain = $this->getCorrelationChain();

        if (count($chain) < 2) {
            return $chain;
        }

        return [$chain[0], $chain[array_key_last($chain)]];
    }

    /**
     * Replace the active correlation chain.
     */
    public function setCorrelationChain(array $chain): void
    {
        $this->correlationChain = $this->normalizeCorrelationChain($chain);
    }

    /**
     * Start a new correlation chain for the current HTTP request.
     *
     * @throws RuntimeException
     * @throws OverflowException
     * @throws LockTimeoutException
     */
    public function startRequestCorrelation(Request $request): int
    {
        $this->setCorrelationChain([]);

        $id = $this->generateCorrelationId();

        $this->setCorrelationChain([$this->makeCorrelation($id, $request, 'request')]);

        return $id;
    }

    /**
     * Append a new Artisan correlation ID to the active chain.
     *
     * @throws RuntimeException
     * @throws OverflowException
     * @throws LockTimeoutException
     */
    public function appendArtisanCorrelation(object|string $object): int
    {
        return $this->appendCorrelation($object, 'artisan');
    }

    /**
     * Create a queue correlation chain without changing the active context.
     *
     * @throws RuntimeException
     * @throws OverflowException
     * @throws LockTimeoutException
     */
    public function createQueueCorrelationChain(object|string $object): array
    {
        $id = $this->generateCorrelationId();

        return $this->appendToChain($this->getCorrelationChain(), $id, $object, 'queue');
    }

    /**
     * Generate an ID that does not collide with the active correlation chain.
     *
     * @throws RuntimeException
     * @throws OverflowException
     * @throws LockTimeoutException
     */
    public function generateCorrelationId(): int
    {
        $activeIds = array_column($this->getCorrelationChain(), 'id');

        do {
            $id = $this->idGenerator->generate();
        } while (in_array($id, $activeIds, true));

        return $id;
    }

    /**
     * Append a correlation item to the active chain.
     *
     * @throws RuntimeException
     * @throws OverflowException
     * @throws LockTimeoutException
     */
    private function appendCorrelation(object|string $object, string $type): int
    {
        $id = $this->generateCorrelationId();

        $this->setCorrelationChain(
            $this->appendToChain($this->getCorrelationChain(), $id, $object, $type)
        );

        return $id;
    }

    /**
     * Find a correlation ID by type, optionally starting before the current item.
     */
    private function findCorrelationId(string $type, bool $excludeCurrent = false): ?int
    {
        $this->assertCorrelationType($type);

        $chain = $this->getCorrelationChain();

        if ($excludeCurrent) {
            array_pop($chain);
        }

        foreach (array_reverse($chain) as $item) {
            if ($item['type'] === $type) {
                return $item['id'];
            }
        }

        return null;
    }

    /**
     * Append one validated item to a correlation chain.
     */
    private function appendToChain(array $chain, int $id, object|string $object, string $type): array
    {
        $chain[] = $this->makeCorrelation($id, $object, $type);

        return $chain;
    }

    /**
     * Create one correlation item.
     */
    private function makeCorrelation(int $id, object|string $object, string $type): array
    {
        $this->assertCorrelationType($type);

        return [
            'id' => $id,
            'name' => is_object($object) ? $object::class : $object,
            'type' => $type,
        ];
    }

    /**
     * Keep only valid correlation items without discarding chain history.
     */
    private function normalizeCorrelationChain(array $chain): array
    {
        return array_values(array_filter($chain, function (mixed $item): bool {
            return is_array($item)
                && is_int($item['id'] ?? null)
                && $item['id'] > 0
                && is_string($item['name'] ?? null)
                && $item['name'] !== ''
                && in_array($item['type'] ?? null, self::CORRELATION_TYPES, true);
        }));
    }

    /**
     * Validate a correlation type before it is used to query or create context.
     */
    private function assertCorrelationType(string $type): void
    {
        if (! in_array($type, self::CORRELATION_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported correlation type [{$type}].");
        }
    }
}
