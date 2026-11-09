# grafida/shell-php

The PHP side of the [Grafida desktop shell](https://github.com/grafida/shell): the code that turns a
PHP application into the **worker process** the native shell talks to over a framed stdio protocol.

> **Status: skeleton.** Nothing here is usable yet. The design is tracked in
> [grafida/grafida#82](https://github.com/grafida/grafida/issues/82); this package is built in Phase 1.

Planned contents:

- `Request` / `Response` value types and a `Handler` interface
- the frame codec and `Worker::run()`, the stdio loop (with stray-output protection)
- `StaticFiles`, a static-asset handler that works from a directory or a `phar://`
- `shell.js`, the browser-side helper for the shell's `_native/*` endpoints
- a dev-mode launcher

The wire protocol is specified, and its conformance fixtures are kept, in
[grafida/shell](https://github.com/grafida/shell). This package runs those fixtures in its own test
suite.

## Licence

MIT — see [LICENSE](LICENSE).
