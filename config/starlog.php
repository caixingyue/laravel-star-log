<?php

return [

    // Locale used for this package's log messages; null uses the configured app.locale.
    'locale' => null,

    /*
    |--------------------------------------------------------------------------
    | Route Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure request IDs and incoming HTTP request and response logs.
    | Run AssignRequestId before RouteLog to attach request IDs to these logs.
    |
    */

    'route' => [
        'request_id' => [
            // Response header name for the generated request ID; null disables the header.
            'response_header' => null,

            // Add request_id to Laravel log records created during this request.
            'share_log_context' => true,
        ],

        // Exclude matching routes from request and response logs; request IDs are unaffected.
        'ignore' => [
            // Request path or full URL patterns, such as health or telescope/*.
            'paths' => [],

            // HTTP methods to exclude, such as OPTIONS or HEAD.
            'methods' => [],

            // Exact route names to exclude, such as horizon.stats.
            'route_names' => [],
        ],

        // Field paths and header names whose values are masked in route request and response logs.
        // Use profile.token for a nested field or items.*.token for the token in each list item.
        'sensitive_fields' => [
            'current_password',
            'password',
            'password_confirmation',
            'token',
            'access_token',
            'refresh_token',
            '_token',
            'authorization',
        ],

        'request' => [
            // Request header names to write to logs; [] records none.
            // Names match exactly without regard to casing; wildcards are not supported.
            'headers' => [],

            // Include query parameters in the request log.
            'query' => true,

            // Include request body data in the request log.
            'body' => true,
        ],

        'response' => [
            // Response header names to write to logs; [] records none.
            // Names match exactly without regard to casing; wildcards are not supported.
            'headers' => [],

            // Include response body data in the response log.
            'body' => true,

            // Include view data when the response is rendered from a view.
            'view_data' => false,
        ],

        'limits' => [
            // Maximum characters in an individual string value; null leaves it unbounded.
            'max_string_length' => 1024,

            // Maximum characters of text body content written to logs; null disables this limit.
            // JSON, form data, and HTML are parsed in full, then use the field and array limits.
            'max_body_length' => 4096,

            // Maximum items retained from one array; null leaves it unbounded.
            'max_array_items' => 50,

            // Maximum nesting depth of logged data; null uses the built-in maximum of 64.
            'max_depth' => 8,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure request and response logs emitted through
    | Laravel's HTTP client.
    |
    */

    'http_client' => [
        // Record outgoing HTTP requests, responses, and connection failures when true.
        'enable' => env('STAR_LOG_ENABLE_HTTP_CLIENT', false),

        'request' => [
            // Record outgoing request logs when true; HTTP client logging must also be enabled.
            'enable' => true,

            // Request header names to write to logs; [] records none.
            // Names match exactly without regard to casing; wildcards are not supported.
            'headers' => [],

            // Include URL query parameters in request, response, and connection failure logs.
            'query' => true,

            // Include request body data in the request log.
            'body' => true,
        ],

        'response' => [
            // Record received response logs when true; HTTP client logging must also be enabled.
            'enable' => true,

            // Response header names to write to logs; [] records none.
            // Names match exactly without regard to casing; wildcards are not supported.
            'headers' => [],

            // Include response body data in the response log.
            'body' => true,
        ],

        'connection_failure' => [
            // Record connection failures when true; HTTP client logging must also be enabled.
            'enable' => true,
        ],

        // Field paths and header names whose values are masked in HTTP client logs.
        // Use profile.token for a nested field or items.*.token for the token in each list item.
        // Add username or password to mask that component in URL credentials.
        'sensitive_fields' => [
            'password',
            'token',
            'access_token',
            'refresh_token',
            'authorization',
        ],

        'limits' => [
            // Maximum characters in an individual string value; null leaves it unbounded.
            'max_string_length' => 1024,

            // Maximum characters of text body content written to logs; null disables this limit.
            // JSON, form data, and HTML are parsed in full, then use the field and array limits.
            'max_body_length' => 4096,

            // Maximum items retained from one array; null leaves it unbounded.
            'max_array_items' => 50,

            // Maximum nesting depth of logged data; null uses the built-in maximum of 64.
            'max_depth' => 8,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL Query Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure SQL query logs emitted by Laravel's database
    | query events.
    |
    */

    'query' => [
        // Enable SQL query logging.
        'enable' => env('STAR_LOG_ENABLE_SQL_QUERY', false),

        // Record a query only when its duration reaches this number of milliseconds.
        'min_time' => 0,

        // Probability of recording an eligible query: 0.0 (never) to 1.0 (always).
        'sample_rate' => 1.0,

        // Maximum SQL entries for the current request, command, or queue job; null leaves it unbounded.
        'max_entries' => null,

        // Maximum SQL text length in characters; null leaves it unbounded.
        'max_sql_length' => 4096,

        'bindings' => [
            // Record binding values when true; false replaces them with [hidden].
            'enable' => false,

            // Maximum characters in each string binding value; null disables truncation.
            'max_length' => 1024,

            // Maximum bindings included in one SQL log entry; null uses the built-in limits.
            'max_count' => 50,

            // Binding log settings by table and column; * supplies defaults for all tables.
            // Table settings override these defaults; model settings override table settings.
            // sensitive masks a verified column's value; max_length limits its string values.
            // max_length accepts a positive integer or null; unmapped bindings are not masked.
            'columns' => [
                '*' => [
                    'password' => ['sensitive' => true],
                ],
            ],
        ],

        // SQL statements matching these rules will not be written to log files.
        // Use table to match the main table, or contains for a string or array of SQL fragments.
        // When contains is an array, every fragment must be present in the same SQL statement.
        // If both are configured in one rule, both must match.
        'ignore' => [
            // Exclusion rules for SQL issued by any class.
            'global' => [
                //
            ],

            // Exclusion rules for SQL whose detected calling class matches the specified class.
            'classes' => [
                Illuminate\Auth\EloquentUserProvider::class => [
                    ['table' => 'users'],
                ],
                Illuminate\Session\DatabaseSessionHandler::class => [
                    ['table' => 'sessions'],
                ],
                Illuminate\Cache\DatabaseStore::class => [
                    ['table' => 'cache'],
                ],
                Illuminate\Cache\DatabaseLock::class => [
                    ['table' => 'cache_locks'],
                ],
                Illuminate\Queue\DatabaseQueue::class => [
                    ['table' => 'jobs'],
                ],
                Illuminate\Bus\DatabaseBatchRepository::class => [
                    ['table' => 'job_batches'],
                ],
                Illuminate\Queue\Failed\DatabaseFailedJobProvider::class => [
                    ['table' => 'failed_jobs'],
                ],
                Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider::class => [
                    ['table' => 'failed_jobs'],
                ],
            ],
        ],
    ],

];
