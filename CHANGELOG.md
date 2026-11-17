# Changelog

The package is versioned in step with Dynoxide: version 1.7.0 of this package
runs Dynoxide 1.7.0.

## [Unreleased]

## [1.7.0.1] - 2026-11-17

Still runs Dynoxide 1.7.0. A fourth version part marks a fix to the package
alone.

### Fixed

- Starting a server on Windows no longer writes "The system cannot find the
  path specified." to stderr, which failed any test run in a separate
  process.

## [1.7.0] - 2026-11-17

- First release: start Dynoxide from PHP tests, with a PHPUnit extension.
- `Dynoxide::locate()`, `Parallel::workerDatabase()` and
  `Extension::server()`, for framework integrations that build on the
  package rather than copy it.
- `Download\Url::isLoopback()` is public API too, for the same
  integrations to tell whether a server is bound to this machine only.
