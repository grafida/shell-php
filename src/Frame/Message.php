<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Frame;

/**
 * A decoded frame: one of {@see Ready}, {@see RequestFrame}, {@see ResponseFrame}, {@see Shutdown}.
 */
interface Message
{
}
