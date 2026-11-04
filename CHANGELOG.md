# Changelog

All notable changes to `sailantis/volucer-worker` are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

Versions are also tagged in git, and `composer require` resolves to the highest tag — so a fix
listed here as unreleased is not installed by anyone until the tag exists.

## [Unreleased]

Everything below is on `main` and is **not** in `0.2.0`. A deployment on `0.2.0` has the two
read-deadline bugs described under *Fixed*.

### Added

- **`Multipart`** — parses `multipart/form-data`, so a worker can accept a file upload. The body
  is handed to the parser as it arrives rather than being buffered whole.
- **`Uploads`** — `is_uploaded_file()` and `move_uploaded_file()` semantics for a worker that
  never went through PHP's own upload handling, plus the reason FFI cannot be used to fake it.
- **`StreamedResponse`** — a generated body is emitted as it is produced instead of being held in
  memory until it is complete.
- **Streamed request bodies** — a request whose length the server did not declare is read until
  the `BODY` frame marked `LAST`, rather than rejected.
- **`answers_ping` is advertised in the `HELLO_ACK`.** The SDK has always answered a `PING` with a
  `PONG`, but the server had no way to know that, so it never sent one — and it cannot find out by
  trying, because a worker that does *not* understand a `PING` treats it as an unknown message type
  and exits, since skipping one would misalign its reader. Announcing the capability is what lets a
  deployment turn on the server's `idle_ping_ms`, which pings an idle connection so that a read
  deadline cannot land on it. A worker on an older SDK simply never advertises it and is never
  pinged, which is what it did before.

  This pairs with the read-deadline fix below and does not replace it: that one makes the worker
  tolerate a deadline, this one lets the server keep the deadline from arriving at all.

### Fixed

- **A read deadline is not a closed connection.** PHP applies `default_socket_timeout` (60 s by
  default) to reads on a socket stream, and `stream_get_contents()` returns `''` both for a read
  that hit that deadline and for a read that hit end-of-file. `readHeader()` read the first as the
  second, so a worker whose server had been quiet for a minute treated it as "the server is
  finished with me", returned from `run()`, and exited — silently, because a clean EOF is the
  documented way to stop a worker. With a pool of N attached workers this happened to **all N at
  once**, and the server answered the next request with a 502 while it rebuilt them. It made any
  low-traffic deployment lose its pool every quiet minute.

  `feof()` separates the two, and it is free. `readHeader()` now waits out a quiet connection and
  returns `null` only for a real close. `readPayload()` had the same bug in a louder form — a
  payload that timed out half-read was reported as a truncated frame, throwing
  `ProtocolException` and killing the worker — and now waits through a deadline via the new
  `readExactly()`.

- **A frame header that arrives in pieces was rejected.** `stream_get_contents($h, 16)` does not
  block until it has 16 bytes; it returns as soon as anything is available, and it returns a
  partial buffer when the deadline lands mid-header. `readHeader()` passed that straight to
  `Header::decode()`, which throws on anything that is not exactly 16 bytes — so a merely *slow*
  server killed the worker with:

      ProtocolException: a frame header is exactly 16 bytes, got 8

  This is the same defect as the one above, reached through the loud door instead of the quiet
  one: a deadline is not a close, whether it lands between frames or in the middle of one.
  `readHeader()` now reassembles, and distinguishes three endings by whether its buffer is empty —
  clean end with an empty buffer is a shutdown (`null`), a clean end with a partial buffer is a
  real truncation (`ProtocolException`, named as one), and a partial buffer that timed out is a
  slow peer and keeps reading.

Setting `default_socket_timeout = -1` also avoids both bugs, and remains supported. It cannot be
relied on as *the* fix, though: a `-d` flag on the supervisor is invisible to a worker that
inherits the connection, and a framework bootstrap or an application's own `ini_set` can put a
deadline back. The deadline is a policy for how long a *request* may take, and the protocol
already carries one (`request_timeout`, enforced by the server); a deadline on the idle read is a
different thing and must not be able to end a worker.

### Notes

- Both read-deadline fixes have regression tests that fail against the code they fix.
  `tests/ReadDeadlineTest.php` runs a real worker in a subprocess over a real socket, because a
  `php://memory` stream never times out and a test over one passes against the broken code.

## [0.2.0] — 2026-10-23

### Added

- **`Rpc`** and **`CallPlane`** — the call plane, so a worker can issue calls back to the server.

### Notes

- `Rpc` had existed since `f3d07ad` and was never tagged, while `sailantis/volucer` already
  documented `Volucer\Rpc::connect()` as the FastCGI half of `[rpc]`. A deployment following that
  page installed `0.1.0`, which has no such class, and got `Class "Volucer\Rpc" not found`.

## [0.1.0] — 2026-10-23

First release: the worker protocol, the request/response exchange, and the supervisor.

[Unreleased]: https://github.com/sailantis/volucer-php-sdk/compare/v0.2.0...main
[0.2.0]: https://github.com/sailantis/volucer-php-sdk/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/sailantis/volucer-php-sdk/releases/tag/v0.1.0
