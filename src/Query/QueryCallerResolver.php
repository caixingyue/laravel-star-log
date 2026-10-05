<?php

namespace Caixingyue\LaravelStarLog\Query;

final class QueryCallerResolver
{
    private const BACKTRACE_LIMIT = 30;

    /**
     * Resolve the closest class that initiated a query outside database plumbing.
     */
    public function resolveClassName(): ?string
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_LIMIT);

        foreach ($backtrace as $frame) {
            $class = $frame['class'] ?? null;

            if (! is_string($class) || $this->isInfrastructureClass($class)) {
                continue;
            }

            return $class;
        }

        return null;
    }

    /**
     * Determine whether a stack frame belongs to logging or framework plumbing.
     */
    private function isInfrastructureClass(string $class): bool
    {
        return str_starts_with($class, 'Caixingyue\\LaravelStarLog\\')
            || str_starts_with($class, 'Illuminate\\Database\\')
            || str_starts_with($class, 'Illuminate\\Events\\')
            || str_starts_with($class, 'Illuminate\\Container\\')
            || str_starts_with($class, 'Illuminate\\Log\\')
            || str_starts_with($class, 'Illuminate\\Support\\Facades\\')
            || str_starts_with($class, 'Monolog\\')
            || str_starts_with($class, 'Psr\\Log\\');
    }
}
