<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Tests\Conformance;

use Grafida\Shell\Frame\Codec;
use Grafida\Shell\Frame\FrameReader;
use Grafida\Shell\Frame\Message;
use Grafida\Shell\Frame\RequestFrame;
use Grafida\Shell\Frame\ResponseFrame;
use Grafida\Shell\Testing\Fixtures;
use Grafida\Shell\Tests\Support\TestStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every conformance fixture from grafida/shell against the PHP codec (protocol/v1.md §8),
 * the same four ways the Rust suite does.
 *
 * A failing fixture is a question about which side is wrong — this codec, the Rust one or the
 * spec — never a file to regenerate.
 */
final class FixtureTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testDecodesAsSpecified(string $name): void
    {
        $stream = fopen('php://memory', 'w+b');
        \assert($stream !== false);
        fwrite($stream, Fixtures::bytes($name));
        rewind($stream);

        [$frames, $end] = Fixtures::decodeAll($stream);
        self::check($name, $frames, $end);
    }

    #[DataProvider('fixtures')]
    public function testDecodesTheSameFromOneByteReads(string $name): void
    {
        [$frames, $end] = Fixtures::decodeAll(TestStream::open('one-byte', Fixtures::bytes($name)));
        self::check($name, $frames, $end);
    }

    /**
     * "A clean protocol error, never a hang": every error but `truncated` (which is defined by EOF)
     * must come from the bytes already received, even when the writer keeps the pipe open.
     */
    #[DataProvider('fixtures')]
    public function testErrorsAreReportedWithoutWaitingForMoreInput(string $name): void
    {
        $end = Fixtures::expected($name)['end'];

        if ($end === 'eof' || $end === 'truncated') {
            self::assertContains($end, ['eof', 'truncated']);

            return;
        }

        [$frames, $got] = Fixtures::decodeAll(TestStream::open('open-pipe', Fixtures::bytes($name)));
        self::check($name, $frames, $got);
    }

    #[DataProvider('fixtures')]
    public function testEveryValidFrameRoundTripsThroughTheEncoder(string $name): void
    {
        $stream = fopen('php://memory', 'w+b');
        \assert($stream !== false);
        fwrite($stream, Fixtures::bytes($name));
        rewind($stream);
        [$frames] = Fixtures::decodeAll($stream);

        foreach ($frames as $i => $frame) {
            $encoded = fopen('php://memory', 'w+b');
            \assert($encoded !== false);
            fwrite($encoded, Codec::encode($frame));
            rewind($encoded);

            $back = (new FrameReader($encoded))->read();
            self::assertNotNull($back, "$name: frame $i");
            self::assertSame(Codec::normalForm($frame), Codec::normalForm($back), "$name: frame $i header");
            self::assertSame(self::body($frame), self::body($back), "$name: frame $i body");
        }

        self::assertGreaterThanOrEqual(0, \count($frames));
    }

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        return Fixtures::provider();
    }

    /** @param list<Message> $frames */
    private static function check(string $name, array $frames, string $end): void
    {
        $expected = Fixtures::expected($name);

        self::assertSame($expected['end'], $end, "$name: end");
        self::assertCount(\count($expected['frames']), $frames, "$name: frame count");

        foreach ($expected['frames'] as $i => $want) {
            $got = $frames[$i];

            // assertEquals, not assertSame: member order is not part of the meaning.
            self::assertEquals($want['header'], Codec::normalForm($got), "$name: frame $i header");

            $body = self::body($got);
            self::assertSame($want['body']['length'], \strlen($body), "$name: frame $i body length");
            self::assertSame($want['body']['sha256'], hash('sha256', $body), "$name: frame $i body sha256");

            if (isset($want['body']['text'])) {
                self::assertSame($want['body']['text'], $body, "$name: frame $i body text");
            }

            if (isset($want['body']['base64'])) {
                self::assertSame(base64_decode($want['body']['base64'], true), $body, "$name: frame $i body base64");
            }
        }
    }

    private static function body(Message $message): string
    {
        return match (true) {
            $message instanceof RequestFrame  => $message->request->body,
            $message instanceof ResponseFrame => $message->response->body,
            default                           => '',
        };
    }
}
