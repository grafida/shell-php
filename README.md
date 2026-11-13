# Grafida Shell – PHP Library

The PHP side of [Grafida Shell](https://github.com/grafida/shell).

> [!TIP]
> **Documentation:** the [user documentation](https://github.com/grafida/shell/blob/main/docs/user/README.md) explains how the shell, this library and the packager fit
> together. Start with [Getting started](https://github.com/grafida/shell/blob/main/docs/user/getting-started.md); [The PHP side](https://github.com/grafida/shell/blob/main/docs/user/php-worker.md) is the full guide to
> this library, and [The JavaScript side](https://github.com/grafida/shell/blob/main/docs/user/javascript.md) covers `shell.js`. This README is a quick reference.

## What does it do

It turns a PHP application into the **worker process** that the native Grafida Shell application talks to.

Communication between the shell and the worker takes place over a framed `stdio` protocol. From your perspective, your
PHP application is a CLI application responding to requests it receives over the protocol.

## Example

```php
use Grafida\Shell\{Handler, Request, Response, StaticFiles, Worker, WorkerOptions};

final class App implements Handler
{
    public function __construct(private StaticFiles $assets) {}

    public function __invoke(Request $request): Response
    {
        if (str_starts_with($request->path(), '/api/')) {
            return Response::json(['ok' => true, 'q' => $request->query('q')]);
        }

        return $this->assets->find($request) ?? Response::html(file_get_contents(__DIR__ . '/index.html'));
    }
}

Worker::run(new App(new StaticFiles(__DIR__ . '/public')), new WorkerOptions(app: 'demo', version: '1.0.0'));
```

| | |
|---|---|
| `Request` | `method`, `url` (verbatim), `headers`, `body`; `path()`, `query()`, `queryParams()`, `header()` |
| `Response` | `body`, `status`, `headers`; `json()`, `html()`, `text()`, `file()` and `with*()` |
| `Headers` | an immutable, case-insensitive map that keeps **every** value of a repeated header; iterates one `name => value` pair per value |
| `Handler` | `__invoke(Request): Response` |
| `Worker::run()` | the stdio loop: `ready` handshake, one request at a time, exit on EOF or `shutdown`; stdout is protected from stray output |
| `StaticFiles` | serves files from directories or `phar://` roots; `find()` answers null on a miss |
| `resources/shell.js` | the page-side helper for the shell's `_native/*` endpoints (dialogs, clipboard, file drops, window control); `ShellScript::path()` finds it |
| `Testing\WorkerProcess` | spawns *your* worker over real pipes for your tests; `playFixture()` runs a conformance fixture through it |

## Using `shell.js` in the page

`resources/shell.js` is the page-side helper for the shell's native features (file dialogs, clipboard, drag and drop,
window control). It defines a global `GrafidaShell`. Serve it from your own origin, either from a route:

```php
if ($request->path() === '/shell.js') {
    return ShellScript::response();
}
```

or by copying `ShellScript::path()` into your public assets at build time, and load it with
`<script src="/shell.js"></script>`.

> [!IMPORTANT]
> `shell.js` is not a PHP file, so a packaged app only has it if you include `vendor/grafida/shell-php/resources` in
> `phar.include`; see [Packaging](https://github.com/grafida/shell/blob/main/docs/user/packaging.md#the-configuration-file-shell-packagejson).

The helper's API, its errors, how files are passed as tokens rather than paths, and the native endpoints are in
[The JavaScript side](https://github.com/grafida/shell/blob/main/docs/user/javascript.md).

## The wire protocol

The wire protocol specification and its conformance fixtures are kept in [grafida/shell](https://github.com/grafida/shell/blob/main/protocol/v1.md). 

The `grafida/shell-php` project includes a copy of the fixtures in `protocol/fixtures/`, pinned to the shell commit 
specified in `protocol/FIXTURES_REF`. The tests here run every one of them: in process, from one-byte reads, against a
pipe that never closes, and  through a real worker process.

The protocol spec and fixtures are updated by running `scripts/sync-fixtures.sh`. The script assumes the `grafida/shell`
commit we are pulling from is checked out in a sibling directory to ours called `shell`.

## Licence

MIT. See [LICENSE](LICENSE).
