<?php

namespace Caixingyue\LaravelStarLog\Correlation;

use Throwable;
use WeakMap;

/**
 * Retain originating IDs while Laravel reports an exception after its execution ends.
 *
 * @internal
 */
final class ExceptionLogContext
{
    /**
     * @var WeakMap<Throwable, array<string, int>>
     */
    private WeakMap $contexts;

    public function __construct()
    {
        $this->contexts = new WeakMap;
    }

    /**
     * Keep the innermost originating execution when the same exception propagates.
     *
     * @param  list<array{id: int, name: string, type: 'request'|'artisan'|'queue'}>  $chain  Validated by CorrelationContext.
     */
    public function remember(Throwable $exception, array $chain): void
    {
        $context = $this->findContext($exception);

        if ($context !== null) {
            $this->contexts[$exception] = $context;

            return;
        }

        $context = [];

        foreach ($chain as $item) {
            $context[$item['type'] . '_id'] = $item['id'];
        }

        $this->contexts[$exception] = $context;
    }

    /**
     * Return IDs for this exception or an originating exception wrapped by application code.
     */
    public function forException(Throwable $exception): array
    {
        return $this->findContext($exception) ?? [];
    }

    /**
     * Distinguish a known uncorrelated origin from an exception not captured yet.
     */
    private function findContext(Throwable $exception): ?array
    {
        for ($depth = 0; $exception !== null && $depth < 64; $depth++, $exception = $exception->getPrevious()) {
            if (isset($this->contexts[$exception])) {
                return $this->contexts[$exception];
            }
        }

        return null;
    }
}
