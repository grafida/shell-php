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
 * Identity and limits of the shell ↔ worker wire protocol.
 *
 * The protocol is specified in grafida/shell (`protocol/v1.md`), which also owns the conformance
 * fixtures this package vendors under `protocol/fixtures/`. The handshake refuses a peer speaking a
 * different version.
 */
final class Protocol
{
    /** Wire protocol version, announced in the worker's `ready` frame. */
    public const int VERSION = 1;

    /** The four bytes every frame starts with. */
    public const string MAGIC = 'GSF1';

    /** The largest header a receiver accepts (fixed by the spec). */
    public const int MAX_HEADER_BYTES = 1 << 20;

    /** The default body limit, per direction; the shell's manifest may change it. */
    public const int DEFAULT_MAX_BODY_BYTES = 128 << 20;

    /** The largest integer any header member may carry: 2^53 − 1, so every JSON reader is exact. */
    public const int MAX_INT = (1 << 53) - 1;

    private function __construct()
    {
    }
}
