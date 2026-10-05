<?php

namespace Caixingyue\LaravelStarLog\Http\Client;

use Caixingyue\LaravelStarLog\Support\LogOptions;
use Caixingyue\LaravelStarLog\Support\SensitiveFieldRules;
use Closure;
use Illuminate\Http\Client\Promises\LazyPromise;

/**
 * Manage temporary HTTP client log options, masking rules, and logging pauses.
 */
final class HttpClientLogState
{
    private array $options = [];

    private array $sensitiveRules = [];

    private int $pauseDepth = 0;

    /**
     * Run the callback with temporary log options and masking rules, then restore the outer scope.
     */
    public function withOptions(array $options, Closure $callback): mixed
    {
        $previous = $this->options;

        $this->options = LogOptions::merge($this->options, array_intersect_key($options, array_flip([
            'enable', 'request', 'response', 'connection_failure', 'limits',
        ])));

        try {
            return $this->withSensitiveFields($options['sensitive_fields'] ?? [], true, $callback);
        } finally {
            $this->options = $previous;
        }
    }

    /**
     * Disable logging for requests started in the callback, including nested scopes.
     */
    public function withoutLogging(Closure $callback): mixed
    {
        $this->pauseDepth++;

        try {
            return $this->run($callback);
        } finally {
            $this->pauseDepth--;
        }
    }

    /**
     * Temporarily mask or expose selected field paths, restoring the rules when the callback exits.
     */
    public function withSensitiveFields(array $fields, bool $sensitive, Closure $callback): mixed
    {
        $this->sensitiveRules[] = ['fields' => $fields, 'sensitive' => $sensitive];

        try {
            return $this->run($callback);
        } finally {
            array_pop($this->sensitiveRules);
        }
    }

    /**
     * Apply active options, field rules, and logging pauses to the supplied configuration.
     */
    public function resolve(array $config): array
    {
        $config = LogOptions::merge($config, $this->options);
        $fields = is_array($config['sensitive_fields'] ?? null) ? $config['sensitive_fields'] : [];
        $config['_sensitive_field_rules'] = new SensitiveFieldRules($fields, $this->sensitiveRules);

        if ($this->pauseDepth > 0) {
            $config['enable'] = false;
        }

        return $config;
    }

    /**
     * Start returned lazy requests while the scope is active, without waiting for completion.
     * Existing promises, values, array keys, and callback exceptions are preserved.
     */
    private function run(Closure $callback): mixed
    {
        $result = $callback();
        $remaining = 10000;
        $this->startPromises($result, 0, $remaining);

        return $result;
    }

    /**
     * Start returned lazy requests, including those in arrays, within depth and item limits.
     */
    private function startPromises(mixed $result, int $depth, int &$remaining): void
    {
        if ($depth >= 64 || --$remaining < 0) {
            return;
        }

        if ($result instanceof LazyPromise && $result->promiseNeedsBuilt()) {
            $result->buildPromise();
        } elseif (is_array($result)) {
            foreach ($result as $value) {
                $this->startPromises($value, $depth + 1, $remaining);
            }
        }
    }
}
