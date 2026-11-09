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
 * Identity of the shell ↔ worker wire protocol.
 *
 * The protocol version is shared with grafida/shell, which owns the specification and the
 * conformance fixtures; the handshake refuses a peer speaking a different version.
 */
final class Protocol
{
    /** Wire protocol version, announced in the worker's `ready` frame. */
    public const int VERSION = 1;

    /** The four bytes every frame starts with. */
    public const string MAGIC = 'GSF1';

    private function __construct()
    {
    }
}
