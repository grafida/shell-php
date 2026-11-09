<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Frame;

/** `shutdown`: either direction (protocol/v1.md §3.4). */
final readonly class Shutdown implements Message
{
}
