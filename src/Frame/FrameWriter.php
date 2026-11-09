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
 * Writes whole frames to a stream and flushes after each one, so a frame never sits in a buffer
 * while the shell waits for it.
 */
final class FrameWriter
{
    /** @param resource $stream a writable stream (the worker's dedicated `php://stdout` handle) */
    public function __construct(private $stream) {}

    /**
     * @throws ProtocolException when the message breaks the spec (nothing is written)
     * @throws \RuntimeException when the stream cannot be written, e.g. the shell has gone
     */
    public function write(Message $message): void
    {
        $this->writeBytes(Codec::encode($message));
    }

    /** @throws \RuntimeException */
    public function writeBytes(string $bytes): void
    {
        $length  = \strlen($bytes);
        $written = 0;

        while ($written < $length) {
            $n = @fwrite($this->stream, $written === 0 ? $bytes : substr($bytes, $written));

            if ($n === false || $n === 0) {
                throw new \RuntimeException('Cannot write to the frame stream');
            }

            $written += $n;
        }

        if (!@fflush($this->stream)) {
            throw new \RuntimeException('Cannot flush the frame stream');
        }
    }
}
