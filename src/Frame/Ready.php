<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Frame;

/** `ready`: worker → shell, exactly once, before anything else (protocol/v1.md §3.1). */
final readonly class Ready implements Message
{
    public function __construct(
        public int $protocol,
        public int $pid,
        public ?string $app = null,
        public ?string $version = null,
    ) {}
}
