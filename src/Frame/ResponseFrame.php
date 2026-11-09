<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Frame;

use Grafida\Shell\Response;

/** `response`: worker → shell (protocol/v1.md §3.3). The body travels inside the {@see Response}. */
final readonly class ResponseFrame implements Message
{
    public function __construct(
        public int $id,
        public Response $response,
    ) {}
}
