<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Tests\Support;

/**
 * A stream wrapper over an in-memory byte string, with two behaviours the frame reader must cope
 * with:
 *
 * - `one-byte://`: every read returns at most one byte, the way a pipe can. The reader must
 *   assemble frames from short reads (wry's Linux body reader stopped at the first one; ADR 0001).
 * - `open-pipe://`: once the data is exhausted the stream is *not* at EOF, as with a writer that
 *   never closes its end. A read there would block forever, so here it throws. The reader must
 *   report every error other than `truncated` from the bytes it already has.
 *
 * Open with {@see open()}, which registers the wrappers and disables PHP's read buffering so each
 * `fread()` reaches {@see stream_read()} with the size the reader asked for.
 */
final class TestStream
{
    /** @var array<string, string> */
    private static array $data = [];

    private static int $next = 0;

    /** @var resource|null set by PHP for every wrapper instance */
    public $context;

    private string $bytes = '';

    private int $position = 0;

    private bool $onePerRead = false;

    /** @return resource */
    public static function open(string $mode, string $bytes)
    {
        foreach (['one-byte', 'open-pipe'] as $scheme) {
            if (!\in_array($scheme, stream_get_wrappers(), true)) {
                stream_wrapper_register($scheme, self::class);
            }
        }

        $key              = 's' . ++self::$next;
        self::$data[$key] = $bytes;
        $stream           = fopen($mode . '://' . $key, 'rb');
        \assert($stream !== false);
        stream_set_read_buffer($stream, 0);

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $scheme = (string) parse_url($path, \PHP_URL_SCHEME);
        $key    = (string) parse_url($path, \PHP_URL_HOST);

        $this->bytes      = self::$data[$key] ?? '';
        $this->onePerRead = $scheme === 'one-byte';
        $this->openPipe   = $scheme === 'open-pipe';
        unset(self::$data[$key]);

        return true;
    }

    private bool $openPipe = false;

    public function stream_read(int $count): string
    {
        $remaining = \strlen($this->bytes) - $this->position;

        if ($remaining === 0 && $this->openPipe) {
            throw new \LogicException('The reader waited for bytes that would never come');
        }

        $n     = min($count, $remaining, $this->onePerRead ? 1 : $count);
        $chunk = substr($this->bytes, $this->position, $n);
        $this->position += $n;

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return !$this->openPipe && $this->position >= \strlen($this->bytes);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return true;
    }
}
