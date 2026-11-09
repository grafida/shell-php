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
 * A violation of the wire protocol. Every one is fatal to the stream: once a frame is malformed the
 * stream is out of sync, so the receiver stops reading (protocol/v1.md §4).
 *
 * {@see $error} is one of the spec's error codes, shared with the Rust side, the fixtures and the
 * logs; the message is for humans only.
 */
final class ProtocolException extends \RuntimeException
{
    public const string TRUNCATED        = 'truncated';
    public const string BAD_MAGIC        = 'bad_magic';
    public const string HEADER_TOO_LARGE = 'header_too_large';
    public const string BODY_TOO_LARGE   = 'body_too_large';
    public const string BAD_JSON         = 'bad_json';
    public const string BAD_HEADER       = 'bad_header';
    public const string UNKNOWN_TYPE     = 'unknown_type';

    public function __construct(
        public readonly string $error,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($error . ': ' . $message, 0, $previous);
    }
}
