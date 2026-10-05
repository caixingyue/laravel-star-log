# Changelog

All notable changes to `laravel-star-log` will be documented in this file.

## 2.0.0 (2026-10-05)

This is a breaking release. Review the upgrade guide below before updating from 1.x.

### Added

- Add optional `AttachStarLogProcessor` tap to preserve original correlation IDs, call sites, and IPs in buffered logs.
- Add request, Artisan command, and queue correlation chains with 13-digit IDs.
- Add `ShouldBeCorrelated` and `InteractsWithCorrelation`, with accessors for the current, parent, and nearest IDs.
- Add configurable request ID response headers and shared Laravel log context.
- Add separate request and response header allowlists for route and HTTP client logs, with shared masking and output limits.
- Add temporary HTTP client log options and masking rules, with independent request, response, and connection-failure switches. Settings also apply to asynchronous requests and retries.
- Add scoped SQL options, condition predicates, and sensitive-column overrides that take precedence over model column settings.
- Add typed HTTP body descriptions, file metadata, and HTML title, description, and heading summaries.
- Add HTTP client connection-failure logs with name-resolution, timeout, and TLS failure reasons.
- Add route exclusions by path, method, and route name.
- Add SQL duration thresholds, sampling, entry limits, and SQL text and binding limits.
- Add SQL binding column settings globally, per table, or on models using `HasQueryLogBindingColumns`.
- Add global and caller-specific SQL exclusions, supporting a `contains` string or an array whose fragments must all match, and temporary query suppression through the facade.
- Add English and Simplified Chinese log messages and an optional locale.

### Changed

- Avoid duplicate request IDs in the default format; support explicit placement with `%context.request_id%` in custom formats.
- Require Laravel 12.69+ (12.x) or 13.30+ (13.x), 64-bit PHP 8.2+ (PHP 8.3+ for Laravel 13), DOM/libxml, Guzzle 7.15.5+ (7.x), and PSR-7 2.13.1+ (2.x).
- Require a shared persistent default cache store with atomic locks and increments for correlation ID generation.
- Replace `Formatter\StrengthenFormatter` with `Formatters\StarLogFormatter`; logs use explicit `request_id`, `artisan_id`, and `queue_id` labels and omit empty optional sections.
- Replace the Artisan and queue `InjectionId` traits with `ShouldBeCorrelated` and optional `InteractsWithCorrelation` accessors; rename `Agent` to `Support\UserAgentDetector`.
- Rename `http` configuration to `http_client` and `secret_fields` to `sensitive_fields`.
- Use exact HTTP field paths and single-segment `*` wildcards for masking.
- Record error responses returned by Laravel in route logs.
- Write SQL connection and duration in the message and SQL data in structured context; binding values are disabled by default.
- Include masking rules in the default configuration for common HTTP password/token fields, the `Authorization` header, and SQL `password` columns. Add rules in `config/starlog.php` for your application's other sensitive fields.
- Limit framework storage exclusions to the framework caller; application queries against the same table remain visible.

### Security

- Keep route paths URL-encoded in request and response messages to prevent log-line injection through encoded newlines and control characters.
- Apply configured HTTP field masking and output limits to bodies, selected headers, and URL query values.
- Mask SQL bindings with identifiable sensitive columns; other bindings remain visible when binding logging is enabled.
- Add a private vulnerability reporting policy and document supported versions.
- Document the limits of field masking, unstructured text, SQL literals, and correlation ID uniqueness in the README and security policy.

### Fixed

- Allow startup logs before correlation services and the request are initialized, omitting unavailable correlation IDs and IP addresses.
- Correct SQL table exclusions for SELECT subqueries, quoted SQL, comments, SQLite conflict clauses, and MySQL modified or aliased writes.
- Retain command and job correlation IDs in Laravel's default exception logs after execution, including nested and wrapped failures.
- Preserve correlation for synchronous after-commit jobs, including completed parent commands, rolled-back transactions, and repeated dispatches.
- Keep queue IDs available in completion and failure callbacks, and prevent IDs or SQL entry counts from leaking between executions.
- Keep response URLs and masking correct when concurrent requests reuse an HTTP client.
- Preserve HTTP body streams for application use, repeated multipart fields, and file metadata.
- Correct HTTP media-type recognition and connection-failure logging; show unavailable durations as `N/A`.
- Support model binding column rules on Laravel 13 and correct closure call-site labels across supported PHP versions, including PHP 8.5.

### Removed

- Remove Laravel 10 and 11 support, former injection-state mutation APIs, and object-based correlation lookup helpers.
- Remove the old route and query exclusion configuration in favor of structured `ignore` rules.

### Upgrading

See [Upgrading to 2.0.0](README.md#upgrading-to-200) for configuration and API changes, cache requirements, and migration steps.

## 1.0.6 (2025-07-04)

- Optimize HTTP client response log. If the response result is a binary stream, record "(binary stream)" instead of directly recording the entire binary data.

## 1.0.5 (2025-01-20)

- Fix the abnormality of obtaining device.

## 1.0.4 (2025-01-20)

- Routing logs no longer limit response types.
- Added View log to avoid recording unnecessary HTML code.

## 1.0.3 (2024-12-18)

- Change the package from `jenssegers/agent` to `mobiledetect/mobiledetectlib`.
- Add agent class and apply it to routing log.

## 1.0.2 (2024-11-21)

- Fix the problem of invalid request log configuration.
- Optimize the default SQL statements in the configuration file.

## 1.0.1 (2024-09-10)

- Fix SQL exclusions not working.

## 1.0.0 (2024-09-03)

- It was extracted from the existing project and became an independent software package, which has been verified through multiple production iterations.
