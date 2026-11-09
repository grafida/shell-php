# grafida/shell-php — AI assistant orientation

The PHP worker runtime for the Grafida desktop shell (umbrella issue: grafida/grafida#82). A native
shell (`grafida/shell`) owns the window and webview; a PHP app runs as a separate **worker** process
and answers the shell's custom-scheme requests over **stdin/stdout** in length-prefixed frames. This
package is the PHP half of that contract. Grafida is consumer #1; the package must stay
**app-agnostic** — nothing Grafida-specific belongs here.

## Rules that are not guessable from the code

- **The protocol is owned by `grafida/shell`**, not by this repository: the spec
  (`protocol/v1.md`) and the `protocol/fixtures/*.bin` + `*.json` pairs live there. This package
  **vendors** a copy in `protocol/fixtures/`, pinned by `protocol/FIXTURES_REF` (a grafida/shell
  commit), refreshed **only** with `scripts/sync-fixtures.sh [shell-clone] [ref]`. Tests check the copy
  against its `SHA256SUMS`; CI diffs it against grafida/shell at that ref, so the ref must be pushed
  first. A failing fixture is a question about which side is wrong — never a file to regenerate or
  edit. The fixtures ship in the Composer dist on purpose (see `.gitattributes`): apps run them
  against their own worker through `Grafida\Shell\Testing`.
- **The order of checks in `FrameReader::read()` is the spec's (§4) and is normative**: header shape
  is validated before `B` is read; a body on `ready`/`shutdown` is `bad_header` before the body limit;
  both limits are enforced before reading what they announce; never scan for the next magic.
  `unpack('P')` is *signed* in PHP, so a body length ≥ 2^63 arrives negative — it is too large, not
  small. Every wire integer must be an `int` in 0 … 2^53 − 1 (`JSON_BIGINT_AS_STRING` turns anything
  beyond `PHP_INT_MAX` into a string, which is then refused).
- **`Headers` is wire-shaped** (lower-case name → list of values) but iterates one `name => value`
  pair per value, the way Boson's `HeadersMap` did — Grafida's Feature suite iterates headers and
  casts each value to string, and must not be edited for the swap. A numeric header name becomes an
  int array key; anything that encodes the map must cast it to an object first.
- **`Request` and `Response` keep Boson's positional constructors** — `new Request($method, $url,
  $headers, $body)`, `new Response($body, $status, $headers)` — for the same reason. `status` is an
  `int`; `Request::path()` is the raw, still-percent-encoded path (`/` when absent) and `query()`
  decodes like `parse_str()`, as Boson's `Query::get()` did.
- `Protocol::VERSION` must equal the shell's protocol version; the `ready` handshake checks it. Bump
  both together, and only for a wire-incompatible change.
- **stdout belongs to the frame stream.** A stray `echo`, `var_dump()` or PHP warning on stdout
  corrupts it. The worker writes frames through its own `php://stdout` handle with explicit
  `fflush()`, sets `display_errors=stderr`, and wraps every handler call in `ob_start()` whose
  **callback** diverts the buffer to stderr tagged `[stray-output]` and returns `''` — a callback, not
  `ob_get_clean()`, so a buffer PHP flushes at shutdown after a fatal error still lands on stderr.
  `WorkerProcessTest::testNothingAnAppPrintsReachesTheFrameStream` is the proof; remove the buffer
  and it fails with `bad_magic`. Exit status: 0 (EOF/`shutdown`), 65 (protocol/session error),
  74 (stdout gone). A handler that throws is a `500`, never an exit.
- `Worker::serve()` is the loop on any three streams (tests drive it on `php://memory`);
  `Worker::run()` is `serve()` on stdio plus the ini settings and `exit()`.
- No runtime dependencies. The worker runs on a static phpmicro SFX, so every extension or package
  it needs must be justified.

## Conventions

- PHP ^8.4, `declare(strict_types=1)`, every PHP file starts with the MIT docblock used in `src/`.
- `composer test` (PHPUnit) and `composer linter:check` (PHPStan level max + strict rules) must pass.
- Keep a terse `CHANGELOG.md` entry per change (Grafida's `!` `+` `-` `~` `# [HIGH|MEDIUM|LOW]` style).
- Commit directly on `main`.
