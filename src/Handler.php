<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell;

/**
 * The application, as the worker sees it: one request in, one response out.
 *
 * A handler that throws is answered with a `500` by {@see Worker}, which keeps serving; anything it
 * prints is captured and sent to stderr, never to the frame stream.
 */
interface Handler
{
    public function __invoke(Request $request): Response;
}
