# Changelog

All notable changes to `laravel-star-log` will be documented in this file.

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
