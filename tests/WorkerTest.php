<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Tests;

use Grafida\Shell\Frame\Codec;
use Grafida\Shell\Frame\FrameReader;
use Grafida\Shell\Frame\Message;
use Grafida\Shell\Frame\Ready;
use Grafida\Shell\Frame\RequestFrame;
use Grafida\Shell\Frame\ResponseFrame;
use Grafida\Shell\Frame\Shutdown;
use Grafida\Shell\Request;
use Grafida\Shell\Tests\Fixtures\EchoHandler;
use Grafida\Shell\Worker;
use Grafida\Shell\WorkerOptions;
use PHPUnit\Framework\TestCase;

/** The worker loop, in process, on memory streams. WorkerProcessTest does it over real pipes. */
final class WorkerTest extends TestCase
{
    /** @var list<Message> */
    private array $out = [];

    private string $err = '';

    /** @param list<Message|string> $input frames, or raw bytes */
    private function serve(array $input, int $maxBody = 1 << 20): int
    {
        $in  = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        \assert($in !== false && $out !== false && $err !== false);

        foreach ($input as $item) {
            fwrite($in, \is_string($item) ? $item : Codec::encode($item));
        }

        rewind($in);
        $status = Worker::serve(new EchoHandler(), new WorkerOptions('echo', '9.9.9', $maxBody), $in, $out, $err);

        rewind($out);
        $reader    = new FrameReader($out);
        $this->out = [];

        // Decoding everything with the strict reader is the "stdout carries frames only" check.
        while (($message = $reader->read()) !== null) {
            $this->out[] = $message;
        }

        rewind($err);
        $this->err = (string) stream_get_contents($err);

        return $status;
    }

    private static function request(int $id, string $path, string $method = 'GET', string $body = ''): RequestFrame
    {
        return new RequestFrame($id, new Request($method, 'boson://app' . $path, ['x-multi' => ['a', 'b']], $body));
    }

    private function response(int $index): ResponseFrame
    {
        $frame = $this->out[$index] ?? null;
        self::assertInstanceOf(ResponseFrame::class, $frame);

        return $frame;
    }

    public function testAnnouncesReadyThenAnswersEachRequestUntilEof(): void
    {
        self::assertSame(Worker::EXIT_OK, $this->serve([self::request(7, '/a'), self::request(8, '/b', 'POST', 'hello')]));

        $ready = $this->out[0];
        self::assertInstanceOf(Ready::class, $ready);
        self::assertSame(1, $ready->protocol);
        self::assertSame(getmypid(), $ready->pid);
        self::assertSame(['echo', '9.9.9'], [$ready->app, $ready->version]);

        self::assertCount(3, $this->out);
        self::assertSame(7, $this->response(1)->id);
        self::assertSame(8, $this->response(2)->id);

        $echo = json_decode($this->response(2)->response->body, true);
        self::assertIsArray($echo);
        self::assertSame('POST', $echo['method']);
        $headers = $echo['headers'];
        self::assertIsArray($headers);
        self::assertSame(['a', 'b'], $headers['x-multi'] ?? null);
        self::assertSame(hash('sha256', 'hello'), $echo['bodySha256']);
        self::assertSame(['one', 'two'], $this->response(2)->response->headers->all('x-multi'));
        self::assertSame('', $this->err);
    }

    public function testShutdownStopsBeforeTheNextRequest(): void
    {
        self::assertSame(Worker::EXIT_OK, $this->serve([self::request(1, '/'), new Shutdown(), self::request(2, '/')]));
        self::assertCount(2, $this->out);
    }

    public function testEmptyInputIsReadyThenExit(): void
    {
        self::assertSame(Worker::EXIT_OK, $this->serve([]));
        self::assertCount(1, $this->out);
    }

    public function testStrayOutputGoesToStderrTaggedAndNeverIntoTheStream(): void
    {
        self::assertSame(Worker::EXIT_OK, $this->serve([self::request(1, '/stray'), self::request(2, '/')]));

        self::assertSame('ok', $this->response(1)->response->body);
        self::assertSame(2, $this->response(2)->id);
        self::assertStringContainsString("[stray-output] stray text\n[stray-output] second line\n", $this->err);
        self::assertStringContainsString('[stray-output]     [printed] => 1', $this->err);
        self::assertStringContainsString('[stray-output] int(42)', $this->err);
        self::assertStringContainsString('[stray-output] left in a nested buffer the handler never closed', $this->err);
    }

    public function testAHandlerThatThrowsGetsA500AndTheWorkerCarriesOn(): void
    {
        self::assertSame(Worker::EXIT_OK, $this->serve([self::request(1, '/throw'), self::request(2, '/')]));

        $failed = $this->response(1)->response;
        self::assertSame(500, $failed->status);
        self::assertSame('{"error":"internal_error"}', $failed->body);
        self::assertSame('no-store', $failed->header('cache-control'));
        self::assertSame(200, $this->response(2)->response->status);
        self::assertStringContainsString('[handler-error] RuntimeException: deliberate failure', $this->err);
    }

    public function testAnUnencodableResponseBecomesA500(): void
    {
        self::assertSame(Worker::EXIT_OK, $this->serve([self::request(1, '/bad-header')]));

        self::assertSame(500, $this->response(1)->response->status);
        self::assertSame('{"error":"bad_response"}', $this->response(1)->response->body);
        self::assertStringContainsString('[response-error] bad_header', $this->err);
    }

    public function testAResponseOverTheBodyLimitBecomesA500(): void
    {
        self::assertSame(Worker::EXIT_OK, $this->serve([self::request(1, '/big?bytes=1025'), self::request(2, '/big?bytes=1024')], 1024));

        self::assertSame('{"error":"response_too_large"}', $this->response(1)->response->body);
        self::assertSame(1024, \strlen($this->response(2)->response->body));
    }

    public function testFramesAWorkerDoesNotAcceptAreASessionError(): void
    {
        self::assertSame(Worker::EXIT_PROTOCOL, $this->serve([new Ready(1, 1), self::request(1, '/')]));
        self::assertCount(1, $this->out);
        self::assertStringContainsString('[protocol-error] session: a worker does not accept ready frames', $this->err);
    }

    public function testAProtocolErrorStopsTheWorker(): void
    {
        self::assertSame(Worker::EXIT_PROTOCOL, $this->serve([self::request(1, '/'), "not a frame"]));
        self::assertCount(2, $this->out);
        self::assertStringContainsString('[protocol-error] bad_magic', $this->err);
    }

    public function testTheWorkersBodyLimitAppliesToRequests(): void
    {
        self::assertSame(Worker::EXIT_PROTOCOL, $this->serve([self::request(1, '/', 'POST', str_repeat('x', 1025))], 1024));
        self::assertStringContainsString('[protocol-error] body_too_large', $this->err);
    }

    public function testAClosedOutputIsAnIoError(): void
    {
        $in  = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'rb'); // not writable
        \assert($in !== false && $out !== false && $err !== false);

        self::assertSame(Worker::EXIT_IO, Worker::serve(new EchoHandler(), new WorkerOptions(), $in, $out, $err));
    }
}
