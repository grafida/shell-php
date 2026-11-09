<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Tests;

use Grafida\Shell\Protocol;
use PHPUnit\Framework\TestCase;

final class ProtocolTest extends TestCase
{
    public function testMagicIsFourBytes(): void
    {
        self::assertSame(4, \strlen(Protocol::MAGIC));
    }

    public function testVersionIsOne(): void
    {
        self::assertSame(1, Protocol::VERSION);
    }
}
