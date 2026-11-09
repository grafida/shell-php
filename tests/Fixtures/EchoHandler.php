<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Tests\Fixtures;

use Grafida\Shell\Handler;
use Grafida\Shell\Request;
use Grafida\Shell\Response;

/**
 * The conformance suite's handler. By default it echoes what arrived, so a test can check that the
 * request crossed the pipe intact. A few paths misbehave on purpose, to prove the worker contains
 * the damage.
 */
final class EchoHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        switch ($request->path()) {
            case '/stray':
                // Every way a PHP app leaks output onto stdout; none of it may reach the frame stream.
                echo "stray text\nsecond line\n";
                print_r(['printed' => true]);
                var_dump(42);
                ob_start();
                echo 'left in a nested buffer the handler never closed';

                if ($request->query('warn') !== null) {
                    trigger_error('a deliberate warning', \E_USER_WARNING);
                }

                return Response::text('ok');

            case '/throw':
                throw new \RuntimeException('deliberate failure');

            case '/bad-header':
                // Valid for Headers (no CR/LF/NUL), but not encodable: invalid UTF-8.
                return new Response('', 200, ['x-bad' => "\xff\xfe"]);

            case '/big':
                return new Response(str_repeat('x', (int) $request->query('bytes', '0')));
        }

        return Response::json([
            'method'     => $request->method,
            'url'        => $request->url,
            'headers'    => $request->headers->toArray(),
            'bodyLength' => \strlen($request->body),
            'bodySha256' => hash('sha256', $request->body),
        ])->withHeader('x-multi', ['one', 'two']);
    }
}
