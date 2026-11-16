# Grafida Shell – PHP Library

The PHP side of [Grafida Shell](https://github.com/grafida/shell): it turns a PHP application into the **worker
process** that the native shell talks to over a framed `stdio` protocol, and it ships `shell.js`, the page-side helper
for the shell's native features (dialogs, clipboard, file drops, window control).

## Documentation

All user documentation lives in [grafida/shell](https://github.com/grafida/shell/blob/main/docs/user/README.md). For
this library:

- [Getting started](https://github.com/grafida/shell/blob/main/docs/user/getting-started.md): a minimal app, from
  `composer require` to a running window.
- [The PHP side](https://github.com/grafida/shell/blob/main/docs/user/php-worker.md): `Worker`, `Request`, `Response`,
  `Headers`, `StaticFiles`, serving `shell.js`, and testing your worker with `Testing\WorkerProcess`.
- [The JavaScript side](https://github.com/grafida/shell/blob/main/docs/user/javascript.md): `shell.js` and the native
  endpoints.
- [Packaging](https://github.com/grafida/shell/blob/main/docs/user/packaging.md): including `shell.js` in the PHAR
  (`phar.include`), and the PHP runtime a packaged app runs on.

## The wire protocol (for maintainers)

The wire protocol specification and its conformance fixtures are kept in
[grafida/shell](https://github.com/grafida/shell/blob/main/protocol/v1.md).

This repository includes a copy of the fixtures in `protocol/fixtures/`, pinned to the shell commit specified in
`protocol/FIXTURES_REF`. The tests here run every one of them: in process, from one-byte reads, against a pipe that
never closes, and through a real worker process.

The protocol spec and fixtures are updated by running `scripts/sync-fixtures.sh`. The script assumes the
`grafida/shell` commit we are pulling from is checked out in a sibling directory to ours called `shell`.

## Licence

MIT. See [LICENSE](LICENSE).
