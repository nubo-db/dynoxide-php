# Changelog

The package is versioned in step with Dynoxide: version 1.7.0 of this package
runs Dynoxide 1.7.0.

## [Unreleased]

### Added

- **`start()` takes a `port` option.** It defaults to 0, so the operating
  system still picks a free port. A fixed port is for dev setups where
  something needs the endpoint in advance. If the port is taken, `start()`
  throws the new `PortInUseException`, which extends `StartException` and
  holds the port in its `port` property. That needs Dynoxide 1.8.0, which
  gives a taken port its own exit status; with an older binary a taken port
  is a plain `StartException`, and its stderr lines name the port. A port
  outside 0 to 65535 throws a `DynoxideException` before anything starts, and
  so does a fixed port with a binary older than 1.7.0. The PHPUnit extension
  stays on port 0.

### Fixed

- **Stopping a server reaches every process its wrapper started.** The walk
  over a server's process tree stopped at 256 processes, so a wrapper that
  had started more than that left the rest running after `stop()`. The walk
  now has a ten-second limit instead, after which it kills everything it has
  found.
- **Stopping a server no longer misses a child started at that moment.** A
  stop signal that reached a wrapper in the middle of starting a child let
  that child appear after the wrapper's children had been read, so it was
  left running. `stop()` now waits for each process to stop before reading
  its children.
- **No more warnings in Pest from the leftover sweep.** A `dynoxide-*`
  directory in the temp directory without an `owner.json` made Pest report a
  warning on every run that started Dynoxide, and on Linux so did a record
  whose processes had gone. The reads that are expected to fail no longer
  reach the error handler. PHPUnit didn't show these, because they were
  silenced with `@`.
  ([#8](https://github.com/nubo-db/dynoxide-php/issues/8))

### Changed

- `StartException` is no longer `final`, so `PortInUseException` can extend
  it.

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
