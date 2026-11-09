# grafida/shell-php

The PHP side of the [Grafida desktop shell](https://github.com/grafida/shell). It turns a PHP
application into the **worker process** that the native shell talks to over a framed stdio protocol.

> **Status: Phase 1.** The value types, the frame codec, the worker loop and `StaticFiles` are
> complete and tested against the protocol's conformance fixtures. The shell that spawns the worker
> arrives in Phase 2 ([grafida/grafida#82](https://github.com/grafida/grafida/issues/82)).

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
| `resources/shell.js` | the page-side helper for the shell's `_native/*` endpoints (contract only until Phase 2); `ShellScript::path()` finds it |
| `Testing\WorkerProcess` | spawns *your* worker over real pipes for your tests; `playFixture()` runs a conformance fixture through it |

The wire protocol is specified, and its conformance fixtures are kept, in
[grafida/shell](https://github.com/grafida/shell/blob/main/protocol/v1.md). This package ships a copy
of the fixtures in `protocol/fixtures/`, pinned to the shell commit in `protocol/FIXTURES_REF`, and its
tests run every one of them: in process, from one-byte reads, against a pipe that never closes, and
through a real worker process.

## Licence

MIT. See [LICENSE](LICENSE).
