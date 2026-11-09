shell-php 0.1.0
================================================================================
+ Repository skeleton: protocol identity, CI, licence
+ `Request`, `Response`, `Headers` (repeated headers kept end to end) and the `Handler` interface
+ Frame codec for wire protocol v1, tested against every grafida/shell conformance fixture (vendored, checksummed, pinned)
+ `Worker::run()`: `ready` handshake, one request at a time, exit on EOF or `shutdown`; stray output goes to stderr, never stdout
+ `StaticFiles`: serves directories and `phar://` roots, refusing `..`, backslashes and NUL
+ `shell.js`: page-side contract for the shell's `_native/*` endpoints
+ `Testing\WorkerProcess`: drive your own worker over real pipes, conformance fixtures included
