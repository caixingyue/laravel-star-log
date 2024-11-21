<?php

namespace Caixingyue\LaravelStarLog;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class QueryServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::listen(function ($query) {
            $enable = StarLog::getConfig('query.enable', false);
            if ($enable) {
                $except = StarLog::getConfig('query.except', []);
                $exceptAll = StarLog::getConfig('query.except.*', []);

                $backtrace = debug_backtrace();
                $backtraceClass = array_column($backtrace, 'class');
                $filteredData = array_intersect_key($except, array_flip($backtraceClass));

                $flattenedSqlList = array_merge(...array_values($filteredData));
                $flattenedSqlList = array_merge($flattenedSqlList, $exceptAll);

                $exists = (bool) Arr::where($flattenedSqlList, fn ($sql) => Str::contains($this->filter($query->sql), $this->filter($sql)));
                if ($exists) return;

                $bindings = Arr::map($query->bindings, function ($binding) {
                    return Str::isJson($binding) ? json_decode($binding) : $binding;
                });

                $data = sprintf(
                    "SQL Query: %s | Bindings: %s | Time: %sms",
                    $query->sql,
                    json_encode($bindings),
                    $query->time
                );

                Log::info($data);
            }
        });
    }

    /**
     * Filter out some values in sql that will affect the judgment.
     *
     * @param string $sql
     * @return string
     */
    private function filter(string $sql): string
    {
        return str_replace(['"', "'", '`'], '', $sql);
    }

    /**
     * Extract table names from SQL statements.
     *
     * @param string $sql
     * @return string|null
     */
    private function extractTableName(string $sql): ?string
    {
        if (preg_match('/\bFROM\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        } elseif (preg_match('/\bINTO\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        } elseif (preg_match('/\bUPDATE\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        } elseif (preg_match('/\bDELETE\s+FROM\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
