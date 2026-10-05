# Security policy

## Supported versions

This policy covers the 2.x release series. Keep the package and its dependencies up to date within that series.

| Package series | Supported Laravel versions | Minimum PHP version |
| --- | --- | --- |
| 2.x | 12.69+ (12.x) | 8.2, 64-bit |
| 2.x | 13.30+ (13.x) | 8.3, 64-bit |

Laravel 10 and 11 are not supported by 2.x. See [composer.json](composer.json) for dependency and extension requirements and the [upgrade guide](README.md#upgrading-to-200) for migration from 1.x. Run `composer audit --locked` in the consuming application and apply dependency security updates.

## Reporting a vulnerability

Report suspected vulnerabilities privately to [xinghuangying@gmail.com](mailto:xinghuangying@gmail.com). Include:

- Affected package, Laravel, and PHP versions.
- A minimal reproduction using synthetic data.
- Expected and observed behavior, and the potential impact.

Redact real request bodies, tokens, and application secrets. Keep exploitable details and private data out of public issues before a fix is available. Use the [issue tracker](https://github.com/caixingyue/laravel-star-log/issues) for non-security bugs.

## Logging boundaries

Route log messages retain URL encoding in paths to prevent encoded newlines and control characters from creating forged log lines.

`StarLogFormatter` follows the channel's `allowInlineLineBreaks` setting for messages, context, and extra data. Set it to `false` when the channel requires single-line output.

Field masking applies to package-generated HTTP logs and SQL bindings with configured, verifiable column mappings. HTTP rules use exact paths: `password` does not also mask `profile.password`. Add application-specific paths, header names, and columns before enabling payload or binding logging. Unlisted fields and unmapped SQL bindings remain visible when their logging is enabled.

Masking does not sanitize arbitrary application log messages or context, SQL literals/comments, URL paths/fragments, or secrets in non-sensitive values, prose, or XML. Malformed JSON can be logged as text. Disable the relevant body/query logging or exclude sensitive routes when field rules cannot cover the data. Restrict access to logs and configure retention in the consuming application.

Output limits control logged data, not request size or body-processing memory. Configure request-size limits in the application and infrastructure. See the README's [HTTP body settings](README.md#route-configuration), [SQL binding settings](README.md#sql-query-logs), and [security boundaries](README.md#security-boundaries).

## Correlation IDs

Correlation IDs are tracing identifiers and must not be used as authentication credentials. Follow the shared-cache setup in [Correlating commands and jobs](README.md#correlating-commands-and-jobs). Cache resets, counter eviction, or process-local stores can repeat IDs; IDs may also repeat across days. Cache failures or reaching the daily limit can prevent correlated execution or job dispatch.
