<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell;

/** How {@see Worker} introduces itself and what it accepts. */
final readonly class WorkerOptions
{
    /**
     * @param ?string $app          the application's name, announced in `ready` for the shell's logs
     * @param ?string $version      the application's version, likewise
     * @param int     $maxBodyBytes the body limit per direction; must match the shell's `worker.maxBodyBytes`
     */
    public function __construct(
        public ?string $app = null,
        public ?string $version = null,
        public int $maxBodyBytes = Protocol::DEFAULT_MAX_BODY_BYTES,
    ) {}
}
