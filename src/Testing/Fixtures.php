<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Testing;

use Grafida\Shell\Frame\FrameReader;
use Grafida\Shell\Frame\Message;
use Grafida\Shell\Frame\ProtocolException;

/**
 * The wire protocol's conformance fixtures, which ship with this package (`protocol/fixtures/`,
 * vendored from grafida/shell at `protocol/FIXTURES_REF`). Their format is grafida/shell
 * `protocol/v1.md` §8.
 *
 * Shipped in `src/` so an application can run the same fixtures against its own worker over a real
 * pipe (see {@see WorkerProcess::playFixture()}). A failing fixture is a question about which side
 * is wrong, never a file to regenerate.
 *
 * @phpstan-type Body array{length: int, sha256: string, text?: string, base64?: string}
 * @phpstan-type ExpectedFrame array{header: array<string, mixed>, body: Body}
 * @phpstan-type Expected array{description: string, frames: list<ExpectedFrame>, end: string}
 * @phpstan-type WorkerOutcome array{responses: list<int>, exit: int, error: ?string, closeInput: bool}
 */
final class Fixtures
{
    private function __construct()
    {
    }

    public static function dir(): string
    {
        return \dirname(__DIR__, 2) . '/protocol/fixtures';
    }

    /** @return list<string> every fixture's base name, sorted */
    public static function names(): array
    {
        $names = [];
        $files = scandir(self::dir());

        foreach ($files === false ? [] : $files as $file) {
            if (str_ends_with($file, '.json')) {
                $names[] = substr($file, 0, -5);
            }
        }

        sort($names);

        return $names;
    }

    public static function bytes(string $name): string
    {
        $bytes = file_get_contents(self::dir() . '/' . $name . '.bin');

        if ($bytes === false) {
            throw new \RuntimeException('No fixture ' . $name);
        }

        return $bytes;
    }

    /** @return Expected */
    public static function expected(string $name): array
    {
        /** @var Expected $decoded */
        $decoded = json_decode((string) file_get_contents(self::dir() . '/' . $name . '.json'), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Fixtures as a PHPUnit data provider.
     *
     * @return iterable<string, array{string}>
     */
    public static function provider(): iterable
    {
        foreach (self::names() as $name) {
            yield $name => [$name];
        }
    }

    /**
     * Decodes a whole stream: every frame, then `eof` or the protocol error code.
     *
     * @param resource $stream
     *
     * @return array{0: list<Message>, 1: string}
     */
    public static function decodeAll($stream): array
    {
        $reader = new FrameReader($stream);
        $frames = [];

        try {
            while (($message = $reader->read()) !== null) {
                $frames[] = $message;
            }

            return [$frames, 'eof'];
        } catch (ProtocolException $e) {
            return [$frames, $e->error];
        }
    }

    /**
     * A fixture's bytes cut at frame boundaries: one chunk per decodable frame, then whatever is
     * left (the malformed tail), if anything.
     *
     * @return list<string>
     */
    public static function chunks(string $name): array
    {
        $bytes  = self::bytes($name);
        $stream = fopen('php://memory', 'w+b');
        \assert($stream !== false);
        fwrite($stream, $bytes);
        rewind($stream);

        $reader = new FrameReader($stream);
        $chunks = [];
        $offset = 0;

        try {
            while ($reader->read() !== null) {
                $end      = (int) ftell($stream);
                $chunks[] = substr($bytes, $offset, $end - $offset);
                $offset   = $end;
            }
        } catch (ProtocolException) {
            // The rest is the tail.
        }

        if ($offset < \strlen($bytes)) {
            $chunks[] = substr($bytes, $offset);
        }

        return $chunks;
    }

    /**
     * What a conforming worker does when a shell writes this fixture to its stdin, one request in
     * flight at a time. It is independent of the application's handler:
     *
     * - `responses`: the ids it answers, in order (every `request` before the stream stops);
     * - `exit`: 0 at `shutdown` or a clean EOF, 65 at a protocol error or a frame a worker does not
     *   accept (`ready`, `response`);
     * - `error`: the protocol error code it must log (`session` for an unacceptable frame), or null;
     * - `closeInput`: whether the shell has to close stdin for the worker to see the end. It does not
     *   for any other error: those are decided by the bytes received, so the worker must stop with
     *   its stdin still open. That is the "never a hang" guarantee.
     *
     * @param Expected $expected
     *
     * @return WorkerOutcome
     */
    public static function workerOutcome(array $expected): array
    {
        $responses = [];

        foreach ($expected['frames'] as $frame) {
            $type = $frame['header']['type'] ?? null;

            if ($type === 'request') {
                $id          = $frame['header']['id'] ?? null;
                $responses[] = \is_int($id) ? $id : -1;

                continue;
            }

            return $type === 'shutdown'
                ? ['responses' => $responses, 'exit' => 0, 'error' => null, 'closeInput' => false]
                : ['responses' => $responses, 'exit' => 65, 'error' => 'session', 'closeInput' => false];
        }

        return match ($expected['end']) {
            'eof'       => ['responses' => $responses, 'exit' => 0, 'error' => null, 'closeInput' => true],
            'truncated' => ['responses' => $responses, 'exit' => 65, 'error' => 'truncated', 'closeInput' => true],
            default     => ['responses' => $responses, 'exit' => 65, 'error' => $expected['end'], 'closeInput' => false],
        };
    }
}
