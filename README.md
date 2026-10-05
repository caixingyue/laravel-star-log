# Laravel Star Log

[![Latest Version on Packagist](https://img.shields.io/packagist/v/caixingyue/laravel-star-log.svg?style=flat-square)](https://packagist.org/packages/caixingyue/laravel-star-log)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/caixingyue/laravel-star-log/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/caixingyue/laravel-star-log/actions/workflows/run-tests.yml)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/caixingyue/laravel-star-log/check-code-style.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/caixingyue/laravel-star-log/actions/workflows/check-code-style.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/caixingyue/laravel-star-log.svg?style=flat-square)](https://packagist.org/packages/caixingyue/laravel-star-log)

Laravel Star Log gives Laravel logs a consistent format and adds optional logging for HTTP routes, outbound HTTP requests, and SQL queries. It can also attach a request, Artisan command, or queue job ID to related log entries.

## Requirements

| Laravel version | Minimum PHP version |
| --- | --- |
| 12.69+ (12.x) | 8.2 |
| 13.30+ (13.x) | 8.3 |

A 64-bit PHP build and the DOM and libxml extensions are required. Composer also requires Guzzle 7.15.5+ (7.x) and PSR-7 2.13.1+ (2.x).

Request, command, and job correlation require a persistent default cache store with atomic locks and increments, shared by all workers. See [Correlating commands and jobs](#correlating-commands-and-jobs) for ID availability and cache requirements.

## Installation

```bash
composer require caixingyue/laravel-star-log
```

Publish the configuration when you need to change the defaults:

```bash
php artisan vendor:publish --tag="star-log-config"
```

Laravel discovers the service provider automatically. Apply the configuration examples below to your published `config/starlog.php`.

| Feature | How to enable |
| --- | --- |
| [Log formatting](#log-channel) | Configure `StarLogFormatter` on a log channel |
| [Request IDs and route logs](#request-ids-and-route-logs) | Add `AssignRequestId` and `RouteLog` middleware |
| [Command and job IDs](#correlating-commands-and-jobs) | Implement `ShouldBeCorrelated` |
| [HTTP client logs](#http-client-logs) | Set `STAR_LOG_ENABLE_HTTP_CLIENT=true` |
| [SQL query logs](#sql-query-logs) | Set `STAR_LOG_ENABLE_SQL_QUERY=true` |

HTTP client and SQL query logging are disabled by default. HTTP headers are omitted and SQL binding values are hidden by default. See [field masking and logging limits](#security-boundaries) before enabling payload logging.

Package-generated logs use Laravel's default log channel at `info` level; the channel must allow that level. For an existing 1.x installation, follow [Upgrading to 2.0.0](#upgrading-to-200).

## Log channel

Use `StarLogFormatter` on the channel that should receive Star Log output in `config/logging.php`.

```php
use Caixingyue\LaravelStarLog\Formatters\StarLogFormatter;

'channels' => [
    'star_daily' => [
        'driver' => 'daily',
        'path' => storage_path('logs/laravel.log'),
        'level' => env('LOG_LEVEL', 'debug'),
        'days' => 14,
        'formatter' => StarLogFormatter::class,
        'formatter_with' => [
            'dateFormat' => 'Y-m-d H:i:s.u',
        ],
        'replace_placeholders' => true,
    ],
],
```

Set `LOG_CHANNEL=star_daily` to make it the default channel. A record then looks like this:

```text
[2026-09-30 10:18:12.481209] local.INFO [request_id=7173101520180] [App.Http.Controllers.OrderController@store:56]: Order created {"id": 42}
```

The correlation and call-site sections are omitted when they are unavailable.

For buffering handlers such as Monolog's `BufferHandler` or `FingersCrossedHandler`, add this tap to the same channel to preserve the original correlation IDs, call site, and IPs:

```php
'tap' => [
    Caixingyue\LaravelStarLog\Logging\AttachStarLogProcessor::class,
],
```

For `FingersCrossedHandler`, add `'action_level' => 'error'` and the tap above to `star_daily` to release buffered logs on errors. Custom buffering handler groups need `Caixingyue\LaravelStarLog\Logging\StarLogProcessor` on the buffering handler using `StarLogFormatter`.

Inline line breaks are allowed by default in messages, context, and extra data. Set `allowInlineLineBreaks` to `false` in the channel's `formatter_with` configuration to replace message line breaks with spaces and retain JSON newline escapes in structured context and extra data. The formatter does not truncate messages; HTTP and SQL output limits are configured separately in `config/starlog.php`.

A custom formatter `format` may use `%correlation%`, `%call_site%`, and `%ips%`.

When `%correlation%` already displays the request ID, the formatter omits that ID from `%context%` to avoid duplication. To display it at a specific position in a custom `format`, use `%context.request_id%`.

## Request IDs and route logs

Add this callback to the existing `Application::configure()` chain in `bootstrap/app.php`, keeping the middleware in this order. `AssignRequestId` starts a request correlation chain; `RouteLog` records the incoming request and final response. You can also apply them to a middleware group or individual routes.

```php
use Caixingyue\LaravelStarLog\Http\Middleware\AssignRequestId;
use Caixingyue\LaravelStarLog\Http\Middleware\RouteLog;
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware) {
    $middleware->append([
        AssignRequestId::class,
        RouteLog::class,
    ]);
})
```

Route logs include final responses, including error responses rendered by Laravel.

Use `AssignRequestId` on its own when you want request correlation and the optional response header without route logs. With both middleware and the default configuration, a request and its response are written as two entries. Application logs written between them have the same `request_id`.

```text
[... ] local.INFO [request_id=7173101520180] [System@request:70]: [desktop] @ 203.0.113.17 - POST[orders] - Request: {"route_name":"orders.store","body":{"type":"json","content_type":"application/json","data":{"product_id":42}}}
[... ] local.INFO [request_id=7173101520180] [App.Http.Controllers.OrderController@store:56]: Order created {"id":42}
[... ] local.INFO [request_id=7173101520180] [System@response:68]: Duration[0.03s] - Memory[16MB] - 201[orders] - Response: {"body":{"type":"json","content_type":"application/json","data":{"id":42}}}
```

### Route configuration

`route.request_id.response_header` adds the generated ID to the final response. Set it to the header name your application uses, such as `Request-Id`; keep it `null` to omit the header.

```php
'route' => [
    'request_id' => [
        'response_header' => 'Request-Id',
        'share_log_context' => true,
    ],
],
```

When `share_log_context` is enabled, Laravel log records written during the request also receive `request_id`. This is useful for channels and third-party handlers that do not use `StarLogFormatter`.

Application code can also read the generated ID from `$request->attributes->get('requestId')`.

Use `route.ignore` to skip request and response logs for paths, methods, or named routes. Ignored requests still receive a generated ID and the configured response header when `AssignRequestId` runs.

```php
'route' => [
    'ignore' => [
        'paths' => ['health', 'telescope/*'],
        'methods' => ['OPTIONS'],
        'route_names' => ['horizon.stats'],
    ],
],
```

`route.request.query` and `route.request.body` default to `true`. `route.response.body` also defaults to `true`, while `route.response.view_data` defaults to `false`. Set these switches to `false` to omit the corresponding data. `route.sensitive_fields` masks matching fields in query parameters, bodies, and selected headers.

Use `route.limits` and `http_client.limits` to control how much HTTP data is included in logs. Both use these defaults:

| Setting | Default | Description |
| --- | --- | --- |
| `max_string_length` | `1024` | Maximum characters per string value |
| `max_body_length` | `4096` | Maximum characters per text body |
| `max_array_items` | `50` | Maximum items per array |
| `max_depth` | `8` | Maximum nesting depth |

Set a value to `null` to remove that limit, except `max_depth`, where `null` allows up to 64 levels. Text bodies are subject to both `max_string_length` and `max_body_length`; the smaller limit applies.

These settings limit logged output. They do not cap request size or memory used to process bodies. Disable body logging for payloads that should not be inspected.

Each logged body includes a `type`:

- `empty`, `form`, `multipart`, `json`, `text`, `html`, `binary`, or `unknown` for requests;
- `empty`, `json`, `text`, `html`, `view`, `binary`, `stream`, or `unknown` for responses.

Valid JSON and URL-encoded fields receive field masking. Malformed JSON is logged as bounded text. Field masking cannot reliably sanitize secrets in prose, XML, or other unstructured text; disable body logging or exclude endpoints that may return such credentials.

Use `sensitive_fields` in each HTTP section to select fields to mask with `******`. Paths are exact: `password` selects only the top-level field, `profile.password` selects that nested field, and `items.*.password` selects it in each list item. Each `*` matches one path segment. Selecting a parent masks its entire value, including descendants. Matching is case-insensitive and supports bracket notation, such as `profile[password]`. Add application-specific paths to the defaults:

```php
// Example route.sensitive_fields, retaining the default rules.
'sensitive_fields' => [
    'current_password',
    'password',
    'password_confirmation',
    'token',
    'access_token',
    'refresh_token',
    '_token',
    'authorization',
    'profile.password',
    'user.token',
    'items.*.password',
],
```

HTML logs contain page summaries rather than full markup. View logs include the view name and path; enable `view_data` to include its data. Files are logged as metadata, such as name, media type, and size. File contents and streamed or binary response bodies are omitted.

## Correlating commands and jobs

Request IDs are created by `AssignRequestId`. Artisan commands and queue jobs opt in by implementing `ShouldBeCorrelated`.

Correlation IDs are 13-digit integers, with up to 9,000,000 IDs per application day. All workers must share a persistent default cache store supporting atomic locks and increments, and use the same cache namespace, application key, and timezone. Process-local stores cannot ensure uniqueness across workers. IDs may repeat after cache resets or counter eviction, or on different days; include the log date when searching. Cache failures or reaching the daily limit can prevent correlated requests, commands, or job dispatches from starting.

For a concurrent SQLite database cache, configure `busy_timeout` and `journal_mode` in `config/database.php` to avoid database-lock errors during ID generation.

```php
use Caixingyue\LaravelStarLog\Concerns\InteractsWithCorrelation;
use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class RebuildSearchIndex extends Command implements ShouldBeCorrelated
{
    use InteractsWithCorrelation;

    protected $signature = 'search:rebuild';

    public function handle(): void
    {
        Log::info('Rebuilding index', ['id' => $this->getId()]);
    }
}
```

Use the same interface and trait on a queued job. Read its ID with `getId()` or `getCurrentCorrelationId()` during execution, such as in `handle()`, rather than in `__construct()`. Queue IDs are also available in `failed()` and completion or failure callbacks.

Laravel's default exception logs retain the originating command or job's correlation IDs, including wrapped exceptions and reports written after execution ends.

Each correlated job dispatch receives its own ID. Jobs on Laravel's standard `sync` connection retain their originating chain when `afterCommit()` delays execution until after the calling command returns.

A request that dispatches a correlated job gives the job both a `request_id` and a `queue_id`. The default formatter shows the first and current IDs; use the facade for the full chain. Commands and jobs without `ShouldBeCorrelated` start without a chain. For nested command IDs, use `Artisan::call()`; `$this->call()` keeps the caller's context without creating a new command ID.

Use the facade when the current class does not use the trait:

```php
use Caixingyue\LaravelStarLog\Facades\StarLog;

StarLog::getRequestId();
StarLog::getArtisanId();
StarLog::getQueueId();
StarLog::getNearestArtisanId();
StarLog::getNearestQueueId();
StarLog::getCurrentCorrelation();
StarLog::getParentCorrelation();
StarLog::getCorrelationChain();
```

`getArtisanId()` and `getQueueId()` return the current ID of that type, or the nearest preceding one. The `getNearest…` methods exclude the current command or job. Missing IDs return `null`. `getCorrelationChain()` returns items with `id`, `name`, and `type` (`request`, `artisan`, or `queue`) in execution order, or `[]` when no chain is active.

## HTTP client logs

Set `STAR_LOG_ENABLE_HTTP_CLIENT=true` to automatically log Laravel HTTP client requests, responses, and connection failures.

```php
'http_client' => [
    'enable' => env('STAR_LOG_ENABLE_HTTP_CLIENT', false),
],
```

```text
[... ] local.INFO [request_id=7173101520180] [HttpClient@request:39]: POST[https://api.example.test/orders?token=******] - Request: {"body":{"type":"json","content_type":"application/json","data":{"amount":100}}}
[... ] local.INFO [request_id=7173101520180] [HttpClient@response:41]: Duration[0.12s] - 201[https://api.example.test/orders?token=******] - Response: {"body":{"type":"json","content_type":"application/json","data":{"id":"ord_1"}}}
```

Configure field masking with `http_client.sensitive_fields` and output limits with `http_client.limits`, using the [same rules as route logs](#route-configuration). URL query values matching the configured sensitive fields are masked.

The following settings default to `true`. Set them to `false` to omit the corresponding data from logs:

- `http_client.request.query`: URL query parameters in request, response, and connection-failure logs.
- `http_client.request.body`: request body data.
- `http_client.response.body`: response body data.

Headers are omitted by default; use the allowlists below to include them.

For credentials in URLs such as `https://username:password@example.test`, add `username` and `password` to `http_client.sensitive_fields` to mask their respective values. The defaults include `password`; add `username` if needed.

Connection-failure logs include the HTTP method, masked URL, and a failure reason when available. Response logs show `N/A` when the duration is unavailable.

### Request and response header allowlists

Choose which headers to include in route and HTTP client logs:

| Configuration | Headers written to logs |
| --- | --- |
| `route.request.headers` | Incoming route request headers |
| `route.response.headers` | Final route response headers |
| `http_client.request.headers` | Outgoing HTTP client request headers |
| `http_client.response.headers` | Received HTTP client response headers |

All four default to `[]`, so no headers are logged. To include specific headers, merge these settings into the existing configuration sections:

```php
'route' => [
    'request' => ['headers' => ['Content-Type', 'X-Trace-Id']],
    'response' => ['headers' => ['Content-Type', 'Request-Id']],
],
'http_client' => [
    'request' => ['headers' => ['Content-Type', 'X-Trace-Id']],
    'response' => ['headers' => ['Content-Type', 'X-Trace-Id']],
],
```

Header names are case-insensitive and must be listed individually; wildcards are not supported. Only listed headers that are present are recorded. Header logging is independent of body logging.

To mask a selected header's value with `******`, add its name to `route.sensitive_fields` or `http_client.sensitive_fields`, as appropriate. Both default lists include `authorization`. If you include `Cookie`, `Set-Cookie`, or other sensitive headers, add their names to the corresponding list. The configured output limits also apply to header values.

### Temporary HTTP client log options

These facade methods apply only to HTTP client logs, leaving route logs and actual transport data unchanged:

| Method | Behavior during the callback |
| --- | --- |
| `withHttpClientLogging($callback)` | Enable HTTP client logging while retaining other rules |
| `withoutHttpClientLogging($callback)` | Pause request, response, and connection failure logs |
| `withHttpClientLogOptions($options, $callback)` | Override client log settings using the configuration structure |
| `withHttpClientSensitiveFields($fields, $callback)` | Mask matching headers, query fields, and body fields |
| `withoutHttpClientSensitiveFields($fields, $callback)` | Allow matching fields to be logged without masking |

```php
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Support\Facades\Http;

// Record the masked request body and response headers, omitting response content.
$response = StarLog::withHttpClientLogOptions([
    'enable' => true,
    'request' => [
        'headers' => ['Content-Type', 'X-Trace-Id'],
        'query' => false,
        'body' => true,
    ],
    'response' => [
        'headers' => ['Content-Type', 'X-Trace-Id'],
        'body' => false,
    ],
    'sensitive_fields' => ['phone', 'profile.identity_number'],
    'limits' => ['max_string_length' => 256],
], fn () => Http::post($url, $data));
```

`enable` is the overall logging switch. When enabled, `request.enable`, `response.enable`, and `connection_failure.enable` control each log type separately; all three default to `true`.

Temporary options change only the settings you supply. Header allowlists replace existing lists, while `sensitive_fields` adds masking rules to the existing rules.

To temporarily log a field without masking, use `withoutHttpClientSensitiveFields()`:

```php
$response = StarLog::withoutHttpClientSensitiveFields(
    ['profile.phone'],
    fn () => Http::post($url, $data),
);
```

Only unmask values that are safe to record. The field must already be included by the body, query, or header settings, and output limits still apply. Unmasking a child of a masked parent leaves its siblings masked. For nested callbacks, the innermost matching masking rule takes precedence.

Previous settings are restored when the callback returns or throws; its return value or exception is preserved. Calls to `withHttpClientLogging()` or `withHttpClientLogOptions()` inside `withoutHttpClientLogging()` cannot resume logging.

Send requests inside the callback, or return their Laravel asynchronous promises, individually or in an array. Those requests retain the temporary settings through retries and asynchronous completion. Creating an HTTP client inside the callback and sending the request afterward does not retain those settings.

## SQL query logs

Set `STAR_LOG_ENABLE_SQL_QUERY=true` to enable SQL logging.

SQLite and MySQL are the verified databases for table exclusions and binding masking. Other drivers and non-default MySQL SQL modes are not yet verified.

```php
'query' => [
    'enable' => env('STAR_LOG_ENABLE_SQL_QUERY', false),
    'min_time' => 10,
    'sample_rate' => 1.0,
    'max_entries' => 100,
],
```

`min_time` is measured per query in milliseconds and defaults to `0`. `sample_rate` accepts `0.0` through `1.0`; its default of `1.0` records every eligible query. `max_entries` limits entries per request, command, or job and defaults to `null` (unlimited). Nested commands and synchronous jobs receive their own budget. `max_sql_length` defaults to `4096` characters; set it to `null` for full SQL text.

```text
[... ] local.INFO [request_id=7173101520180] [System@db:219]: Connection[sqlite] - Duration[12.69ms] {"sql":"insert into \"orders\" (\"total\") values (?)","bindings":"[hidden]"}
```

Bindings are hidden as `[hidden]` by default. Enable them only when needed, then configure private columns and output limits. Column masking supports simple INSERT and UPDATE assignments and single-table WHERE conditions, including comparisons, LIKE, IN, BETWEEN, and grouped AND/OR conditions. For example, `WHERE id = ? AND password = ?` keeps the ID and masks the password with the default column settings.

Only bindings reliably matched to a column with `sensitive: true` are masked. Other bindings remain visible when binding logging is enabled; placeholder names alone do not select sensitive columns. Settings under `columns['*']` apply to all tables, table settings override them, and model settings override table settings.

```php
'query' => [
    'bindings' => [
        'enable' => true,
        'max_length' => 1024,
        'max_count' => 50,
        'columns' => [
            'orders' => [
                'payment_token' => ['sensitive' => true],
            ],
        ],
    ],
],
```

Models can define binding column log settings for their own table:

```php
use Caixingyue\LaravelStarLog\Concerns\HasQueryLogBindingColumns;
use Illuminate\Database\Eloquent\Model;

final class Payment extends Model
{
    use HasQueryLogBindingColumns;

    protected static function queryLogBindingColumns(): array
    {
        return [
            'token' => ['sensitive' => true],
        ];
    }
}
```

Model rules apply after the first model instance is created, including to later queries for its table made without the model. Keep these rules independent of the current user, request, or tenant. Use configuration for always-active rules and callback methods for temporary rules. Each column supports `sensitive` and `max_length`; a column's `max_length` overrides the default binding string limit, and `null` disables it.

### Ignoring queries

Use `query.ignore.global` for all callers and `query.ignore.classes` for an exact caller-class match. `table` matches the primary table. `contains` accepts a string or a non-empty array of SQL fragments, ignoring case, whitespace differences, and identifier quotes. An array matches only when every fragment appears in the same SQL statement, in any order. A rule with both `table` and `contains` requires both to match. Any matching rule excludes the log entry.

```php
'query' => [
    'ignore' => [
        'global' => [
            ['table' => 'telescope_entries'],
            ['contains' => 'pragma foreign_keys'],
            ['contains' => ['select', 'from cache', 'where key = ?']],
        ],
        'classes' => [
            App\Services\AuditWriter::class => [
                ['table' => 'audit_logs', 'contains' => ['delete', 'where created_at < ?']],
            ],
        ],
    ],
],
```

Default rules exclude Laravel's session, cache, queue, and authentication storage queries. Application queries against the same tables still log. For a custom authentication table, add the corresponding class rule.

For a local operation, use the facade instead of adding a permanent rule:

```php
use Caixingyue\LaravelStarLog\Facades\StarLog;

StarLog::withoutQueryLogging(function (): void {
    // Queries here are not logged.
});

StarLog::withoutQueryLoggingForTables(['telescope_entries'], function (): void {
    // Only queries whose primary table is telescope_entries are not logged.
});
```

### Temporary SQL log options and filters

| Method | Behavior during the callback |
| --- | --- |
| `withQueryLogging($callback)` | Enable SQL logging while retaining other rules |
| `withoutQueryLogging($callback)` | Pause all SQL logging |
| `withoutQueryLoggingForTables($tables, $callback)` | Ignore queries for the listed primary tables |
| `withQueryLogOptions($options, $callback)` | Override SQL logging settings using the configuration structure |
| `withQuerySensitiveColumns($columns, $callback)` | Mask verified binding columns even when model column settings allow them |
| `withoutQuerySensitiveColumns($columns, $callback)` | Cancel masking for verified columns even when model column settings mask them |
| `withQueryLogFilter($filter, $callback)` | Add a predicate that must return true for a query to be logged |

Use your application's table and column names in the queries below. The sensitive-column examples require `query.enable` and `query.bindings.enable` to be `true`.

```php
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Support\Facades\DB;

StarLog::withQueryLogOptions([
    'enable' => true,
    'min_time' => 100,
    'sample_rate' => 1.0,
    'bindings' => ['enable' => true, 'max_length' => 256, 'max_count' => 20],
], fn () => DB::table('users')->where('email', 'developer@example.test')->first());

StarLog::withQuerySensitiveColumns([
    '*' => ['access_token'],
    'users' => ['phone', 'identity_number'],
], fn () => DB::table('users')
    ->where('phone', '+1-202-555-0100')
    ->where('identity_number', 'demo-id')
    ->first());

// Temporarily unmask phone when an existing rule marks it sensitive.
StarLog::withoutQuerySensitiveColumns([
    'users' => ['phone'],
], fn () => DB::table('users')->where('phone', '+1-202-555-0100')->first());
```

Temporary column rules override configuration and model rules. For nested callbacks, the innermost matching rule wins; within one callback, a table-specific rule overrides `*`. These rules apply only to bindings with known columns. To log unmasked values, binding logging must also be enabled. SQL text and execution are unaffected.

```php
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

StarLog::withQueryLogFilter(
    fn (QueryExecuted $query): bool =>
        $query->connectionName === 'mysql' && $query->time >= 100,
    fn () => DB::table('users')->limit(20)->get(),
);
```

Every active filter must return `true`, and configured rules and limits still apply. Keep filters free of side effects. If a filter throws, the query is omitted from logs without changing its result. Previous settings are restored when the business callback returns or throws; its return value or exception is preserved.

Temporary options change only supplied settings and replace lists. Nested callbacks cannot override an outer logging pause or table exclusion. The `max_entries` limit applies across callbacks within the same request, command, or job. SQL options do not affect HTTP client or route logs.

## Language and translations

Set `locale` in `config/starlog.php` to choose the language used by the package's route, HTTP client, and SQL messages; leave it `null` to use `app.locale`.

The package includes English (`en`) and Simplified Chinese (`zh_CN`) translations. To customize the log messages, publish the language files:

```bash
php artisan vendor:publish --tag="star-log-translations"
```

The files are published to `lang/vendor/star-log/` by default. Edit the `star-log.php` file in the corresponding locale directory to customize its messages. Publishing is optional when using the built-in translations.

## Security boundaries

Field masking applies to package-generated HTTP logs and configured SQL bindings. HTTP paths match case-insensitively and exactly, including bracket and dotted notation; a plain `password` rule does not cover `profile.password`.

| Log type | Default sensitive fields or columns |
| --- | --- |
| Route data and selected headers | `current_password`, `password`, `password_confirmation`, `token`, `access_token`, `refresh_token`, `_token`, `authorization` |
| HTTP client data and selected headers | `password`, `token`, `access_token`, `refresh_token`, `authorization` |
| SQL binding columns | `password` |

Add paths, header names, and SQL column settings for your application's other private fields, such as `api_key`, `client_secret`, `x-api-key`, or nested credentials. HTTP client `current_password` and `password_confirmation`, and SQL `token`, `access_token`, and `refresh_token` are not masked by default. Keep the existing rules when adding your own.

Masking does not sanitize arbitrary application log messages or context, SQL literals/comments, URL paths/fragments, or secrets embedded in unstructured text and non-sensitive values. Malformed JSON can be logged as text. Unmapped SQL bindings remain visible when binding logging is enabled. Disable the relevant body/query logging or exclude sensitive routes when field rules cannot cover the data.

Output limits do not cap request size or body-processing memory. Configure request-size limits in the application and infrastructure, restrict log access, and set appropriate retention.

Correlation IDs are trace identifiers and must not be used as authentication credentials. Daily uniqueness depends on the shared cache described in [Requirements](#requirements); cache resets or counter eviction can repeat IDs.

See [SECURITY.md](SECURITY.md) for supported versions and private vulnerability reporting.

## Upgrading to 2.0.0

Version 2.0 is a breaking release. It drops Laravel 10 and 11 support and requires the PHP, Laravel, extension, and cache setup in [Requirements](#requirements).

### Dependencies and configuration

Back up your existing `config/starlog.php` before replacing it. After updating the package, publish the new defaults and merge your application-specific settings into them:

```bash
cp config/starlog.php config/starlog.php.bak
composer require "caixingyue/laravel-star-log:^2.0" --with-all-dependencies
php artisan vendor:publish --tag="star-log-config" --force
php artisan config:clear
```

The backup command assumes you previously published the configuration. Rebuild the configuration cache if your deployment uses it, and restart long-running workers after deploying the updated code and settings.

| 1.x configuration | 2.0 configuration |
| --- | --- |
| `http` | `http_client` |
| `route.secret_fields` | `route.sensitive_fields` |
| `http.secret_fields` | `http_client.sensitive_fields` |
| `route.response_head_id` / `STAR_LOG_RESPONSE_HEAD_ID` | `route.request_id.response_header`: a header name such as `Request-Id`, or `null` to disable |
| `route.except` | `route.ignore.paths` |
| `route.except_method` | `route.ignore.methods` |
| `query.except['*']` | `query.ignore.global`: SQL fragments become `['contains' => '...']` rules |
| `query.except[SomeClass::class]` | `query.ignore.classes[SomeClass::class]`: use the same structured rule format |

The `STAR_LOG_ENABLE_HTTP_CLIENT` and `STAR_LOG_ENABLE_SQL_QUERY` environment variables retain their names. Update direct `config('starlog.http...')` calls to use `starlog.http_client...`.

### Formatter and correlation APIs

Replace `Caixingyue\LaravelStarLog\Formatter\StrengthenFormatter` with `Caixingyue\LaravelStarLog\Formatters\StarLogFormatter` in your log channels. If you subclassed the old formatter, adapt the subclass to the new formatter's API. Update any manually registered console, queue, or query providers to the `Caixingyue\LaravelStarLog\Providers` namespace; Laravel's package discovery registers them automatically.

| 1.x usage | 2.0 replacement |
| --- | --- |
| `Console\InjectionId` / `Queue\InjectionId` traits | Implement `Contracts\ShouldBeCorrelated`; add `Concerns\InteractsWithCorrelation` for instance accessors |
| `$this->artisanId` / `$this->queueId` properties | Read `$this->getId()` during execution with `InteractsWithCorrelation` |
| `StarLog::getInjectionIds()` | `StarLog::getCorrelationChain()` |
| `getArtisanId($object)` / `getQueueId($object)` | Call without an argument for the current or nearest preceding ID |
| `getInjectionObject()`, `getInjectionLastObject()`, `getInjectionObjectId()`, `getAvailableObjectId()` | Use the current/parent correlation accessors or inspect `getCorrelationChain()`; object lookup is removed |
| `initializeInjectionId()`, `appendRequestId()`, `appendArtisanTaskId()`, `appendQueueTaskId()`, `loadObjectStarLogIds()`, `setStarLogIds()` | Remove manual initialization and state mutation; middleware and command/job execution manage correlation |
| Direct use of `Agent` | `Support\UserAgentDetector` |
| Direct use of `Support\UniqueId` | `StarLog::generateCorrelationId()` for a 13-digit correlation ID; arbitrary-length generation is removed |

The names in this table are relative to `Caixingyue\LaravelStarLog` unless prefixed with `StarLog::`. IDs are available during command/job execution, including `handle`, rather than during construction. Request IDs are now 13 digits, as are command and job IDs. Review database fields, validators, and log parsing rules that assumed the old lengths or format.

### Log output and masking

- Logs use explicit `request_id`, `artisan_id`, and `queue_id` labels and omit unavailable correlation and call-site sections. Update downstream log parsers accordingly.
- Route paths retain URL encoding in log messages.
- HTTP field rules now use exact paths and single-segment `*` wildcards. Add nested paths such as `profile.password` or `items.*.password` where a former rule matched nested fields broadly.
- Route and HTTP client payloads now use typed `body` descriptions and output limits. Review request/response body switches and header allowlists before enabling logs in production.
- Text, including malformed JSON, may be recorded under the body settings. Exclude sensitive endpoints or disable body logging where field masking is insufficient.
- SQL now keeps placeholders in the `sql` context field and records bindings separately. Bindings default to `[hidden]`; enabling them exposes values unless a verified column has a sensitive setting.
- Framework storage queries are excluded by their calling class. Application queries against the same tables can now be logged; add global rules if you need the former exclusions.

See the feature sections above for complete configuration examples and callback APIs.

## Contributing

From a source checkout, install dependencies and run the checks below. Tests require PDO SQLite in addition to the runtime extensions.

```bash
composer install
composer validate --strict
composer audit --locked
composer test
vendor/bin/pint --test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for release notes.

## Credits

- [xingyue cai](https://github.com/caixingyue)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
