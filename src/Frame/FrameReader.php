<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Frame;

use Grafida\Shell\Protocol;

/**
 * Reads frames from a blocking stream, checking in the order protocol/v1.md §4 makes normative.
 *
 * Both length limits are enforced before the bytes they announce are read, and the first malformed
 * byte ends the stream with a {@see ProtocolException}. The reader never scans ahead for the next
 * magic. So a corrupt stream can neither make the worker allocate what a garbage length says nor
 * leave it waiting for bytes that will not come. The body is read in bounded chunks as it arrives.
 */
final class FrameReader
{
    private const int CHUNK = 1 << 20;

    /** @param resource $stream a readable, blocking stream (e.g. `php://stdin`) */
    public function __construct(
        private $stream,
        private readonly int $maxBodyBytes = Protocol::DEFAULT_MAX_BODY_BYTES,
    ) {}

    /**
     * The next frame, or null at a clean end of stream (EOF exactly at a frame boundary).
     *
     * @throws ProtocolException
     * @throws \RuntimeException when the stream cannot be read
     */
    public function read(): ?Message
    {
        // 1. Magic.
        $magic = $this->readUpTo(4);

        if ($magic === '') {
            return null;
        }

        if (\strlen($magic) < 4) {
            throw self::truncated(sprintf('EOF after %d of 4 magic bytes', \strlen($magic)));
        }

        if ($magic !== Protocol::MAGIC) {
            throw new ProtocolException(ProtocolException::BAD_MAGIC, 'expected GSF1, got 0x' . bin2hex($magic));
        }

        // 2. H.
        $h = $this->readExactly(4, 'the header length');
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('V', $h);
        $length   = $unpacked[1];

        if ($length > Protocol::MAX_HEADER_BYTES) {
            throw new ProtocolException(
                ProtocolException::HEADER_TOO_LARGE,
                sprintf('%d bytes > %d', $length, Protocol::MAX_HEADER_BYTES),
            );
        }

        // 3–5. Header bytes, JSON, shape: before B, so a bad header never waits for a body.
        $message = Codec::decodeHeader($this->readExactly($length, 'the header'));

        // 6. B. unpack('P') yields a signed PHP int: 2^63 and above come back negative.
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('P', $this->readExactly(8, 'the body length'));
        $bodyLength = $unpacked[1];

        if ($bodyLength !== 0 && !Codec::allowsBody($message)) {
            throw new ProtocolException(ProtocolException::BAD_HEADER, 'this frame type carries no body');
        }

        if ($bodyLength < 0 || $bodyLength > $this->maxBodyBytes) {
            throw new ProtocolException(
                ProtocolException::BODY_TOO_LARGE,
                sprintf('%s bytes > %d', $bodyLength < 0 ? sprintf('%u', $bodyLength) : $bodyLength, $this->maxBodyBytes),
            );
        }

        // 7. Body.
        return $bodyLength === 0 ? $message : Codec::withBody($message, $this->readExactly($bodyLength, 'the body'));
    }

    /** @throws ProtocolException `truncated` when EOF comes first */
    private function readExactly(int $length, string $what): string
    {
        $data = $this->readUpTo($length);

        if (\strlen($data) !== $length) {
            throw self::truncated(sprintf('EOF after %d of %d bytes of %s', \strlen($data), $length, $what));
        }

        return $data;
    }

    /** Reads until `$length` bytes or EOF, in chunks of at most {@see CHUNK}. */
    private function readUpTo(int $length): string
    {
        $data = '';

        while (\strlen($data) < $length) {
            $chunk = fread($this->stream, max(1, min(self::CHUNK, $length - \strlen($data))));

            if ($chunk === false) {
                throw new \RuntimeException('Cannot read from the frame stream');
            }

            // A blocking stream returns '' only at EOF.
            if ($chunk === '') {
                break;
            }

            $data .= $chunk;
        }

        return $data;
    }

    private static function truncated(string $message): ProtocolException
    {
        return new ProtocolException(ProtocolException::TRUNCATED, $message);
    }
}
