<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 *
 * A real worker process for WorkerProcessTest: `php tests/Fixtures/echo-worker.php [maxBodyBytes]`.
 */

declare(strict_types=1);

use Grafida\Shell\Protocol;
use Grafida\Shell\Tests\Fixtures\EchoHandler;
use Grafida\Shell\Worker;
use Grafida\Shell\WorkerOptions;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

// Output before run() must not reach the stream ahead of `ready` either.
ob_start();
echo 'printed by the entry point before the worker started';

Worker::run(new EchoHandler(), new WorkerOptions(
    app: 'echo',
    version: '9.9.9',
    maxBodyBytes: (int) ($argv[1] ?? Protocol::DEFAULT_MAX_BODY_BYTES),
));
