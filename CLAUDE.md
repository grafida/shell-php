# grafida/shell-php — AI assistant orientation

The PHP worker runtime for the Grafida desktop shell (umbrella issue: grafida/grafida#82). A native
shell (`grafida/shell`) owns the window and webview; a PHP app runs as a separate **worker** process
and answers the shell's custom-scheme requests over **stdin/stdout** in length-prefixed frames. This
package is the PHP half of that contract. Grafida is consumer #1; the package must stay
**app-agnostic** — nothing Grafida-specific belongs here.

## Rules that are not guessable from the code

- **The protocol is owned by `grafida/shell`**, not by this repository: the spec and the
  `protocol/fixtures/*.bin` + `*.json` pairs live there. This package's tests run those fixtures.
  A failing fixture is a question about which side is wrong — never a file to regenerate.
- `Protocol::VERSION` must equal the shell's protocol version; the `ready` handshake checks it. Bump
  both together, and only for a wire-incompatible change.
- **stdout belongs to the frame stream.** A stray `echo`, `var_dump()` or PHP warning on stdout
  corrupts it. The worker writes frames through its own `php://stdout` handle with explicit
  `fflush()`, sets `display_errors=stderr`, and wraps every handler call in `ob_start()`, sending
  anything captured to stderr tagged `[stray-output]`.
- No runtime dependencies. The worker runs on a static phpmicro SFX, so every extension or package
  it needs must be justified.

## Conventions

- PHP ^8.4, `declare(strict_types=1)`, every PHP file starts with the MIT docblock used in `src/`.
- `composer test` (PHPUnit) and `composer linter:check` (PHPStan level max + strict rules) must pass.
- Keep a terse `CHANGELOG.md` entry per change (Grafida's `!` `+` `-` `~` `# [HIGH|MEDIUM|LOW]` style).
- Commit directly on `main`.
