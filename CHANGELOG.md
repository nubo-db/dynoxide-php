# Changelog

The package is versioned in step with Dynoxide: version 1.7.0 of this package
runs Dynoxide 1.7.0.

## [Unreleased]

- First release: start Dynoxide from PHP tests, with a PHPUnit extension.
- `Dynoxide::locate()`, `Parallel::workerDatabase()` and
  `Extension::server()`, for framework integrations that build on the
  package rather than copy it.
- `Download\Url::isLoopback()` is public API too, for the same
  integrations to tell whether a server is bound to this machine only.
