<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Tests;

use Grafida\Shell\Request;
use Grafida\Shell\Testing\Fixtures;
use Grafida\Shell\Testing\WorkerProcess;
use Grafida\Shell\Worker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A real worker process (`tests/Fixtures/echo-worker.php`) over real pipes: the handshake, every
 * conformance fixture, and stdout protection against everything a PHP app can print.
 */
final class WorkerProcessTest extends TestCase
{
    private static function start(?int $maxBody = null): WorkerProcess
    {
        $command = [\PHP_BINARY, '-d', 'display_errors=1', __DIR__ . '/Fixtures/echo-worker.php'];

        if ($maxBody !== null) {
            $command[] = (string) $maxBody;
        }

        return new WorkerProcess($command);
    }

    public function testHandshakeAnnouncesProtocolPidAndApp(): void
    {
        $worker = self::start();

        self::assertSame(1, $worker->ready->protocol);
        self::assertSame($worker->pid(), $worker->ready->pid);
        self::assertSame(['echo', '9.9.9'], [$worker->ready->app, $worker->ready->version]);

        $worker->closeInput();
        self::assertSame(Worker::EXIT_OK, $worker->wait());
        // Output the entry point buffered before run() went to stderr, not ahead of `ready`.
        self::assertStringContainsString('[stray-output] printed by the entry point before the worker started', $worker->stderr());
    }

    /**
     * Every fixture, played into a real worker the way a shell would. Requests are echoed, so the
     * test also proves each one crossed the pipe intact: method, URL, every header value, body.
     */
    #[DataProvider('fixtures')]
    public function testFixtureOverARealPipe(string $name): void
    {
        $expected = Fixtures::expected($name);
        $outcome  = Fixtures::workerOutcome($expected);
        $run      = self::start()->playFixture($name);

        self::assertSame($outcome['exit'], $run['exit'], "$name: exit status. stderr: " . $run['stderr']);
        self::assertSame($outcome['responses'], array_map(static fn ($r): int => $r->id, $run['responses']), "$name: answered ids");

        if ($outcome['error'] !== null) {
            self::assertStringContainsString('[protocol-error] ' . $outcome['error'], $run['stderr'], $name);
        }

        foreach ($run['responses'] as $i => $response) {
            $sent = $expected['frames'][$i];
            $echo = json_decode($response->response->body, true);
            self::assertIsArray($echo, $name);
            self::assertSame($sent['header']['method'], $echo['method'], "$name: method");
            self::assertSame($sent['header']['url'], $echo['url'], "$name: url");
            self::assertEquals($sent['header']['headers'], $echo['headers'], "$name: headers");
            self::assertSame($sent['body']['length'], $echo['bodyLength'], "$name: body length");
            self::assertSame($sent['body']['sha256'], $echo['bodySha256'], "$name: body sha256");
            self::assertSame(['one', 'two'], $response->response->headers->all('x-multi'), "$name: repeated response header");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        return Fixtures::provider();
    }

    /**
     * echo, print_r, var_dump, a handler's unclosed buffer and a PHP warning (with display_errors=1
     * on the command line, which Worker::run() overrides): none of it may reach stdout.
     */
    public function testNothingAnAppPrintsReachesTheFrameStream(): void
    {
        $worker = self::start();

        $response = $worker->request(new Request('GET', 'boson://app/stray?warn=1'));
        self::assertSame('ok', $response->body);

        // The stream is still in sync: the next request is answered normally.
        self::assertSame(200, $worker->request(new Request('GET', 'boson://app/'))->status);

        $worker->closeInput();
        self::assertSame(Worker::EXIT_OK, $worker->wait());

        $stderr = $worker->stderr();
        self::assertStringContainsString("[stray-output] stray text\n[stray-output] second line\n", $stderr);
        self::assertStringContainsString('[stray-output] int(42)', $stderr);
        self::assertStringContainsString('[stray-output] left in a nested buffer the handler never closed', $stderr);
        self::assertStringContainsString('a deliberate warning', $stderr);
    }

    public function testAHandlerThatThrowsDoesNotKillTheWorker(): void
    {
        $worker = self::start();

        self::assertSame(500, $worker->request(new Request('GET', 'boson://app/throw'))->status);
        self::assertSame(200, $worker->request(new Request('GET', 'boson://app/'))->status);
        self::assertStringContainsString('[handler-error] RuntimeException: deliberate failure', $worker->stderr());
    }

    /** A 50 MiB upload, the parity checklist's size, arrives intact. */
    public function testALargeBodyCrossesThePipeIntact(): void
    {
        $worker = self::start();
        $body   = random_bytes(50 << 20);

        $echo = json_decode($worker->request(new Request('POST', 'boson://app/upload', [], $body))->body, true);

        self::assertIsArray($echo);
        self::assertSame(\strlen($body), $echo['bodyLength']);
        self::assertSame(hash('sha256', $body), $echo['bodySha256']);
    }

    public function testTheBodyLimitIsTheOneTheWorkerWasGiven(): void
    {
        $worker = self::start(1024);

        self::assertSame(200, $worker->request(new Request('POST', 'boson://app/', [], str_repeat('x', 1024)))->status);

        $worker->send(new \Grafida\Shell\Frame\RequestFrame(99, new Request('POST', 'boson://app/', [], str_repeat('x', 1025))));
        self::assertSame(Worker::EXIT_PROTOCOL, $worker->wait());
        self::assertStringContainsString('[protocol-error] body_too_large', $worker->stderr());
    }
}
