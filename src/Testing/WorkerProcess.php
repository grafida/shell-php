<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Testing;

use Grafida\Shell\Frame\Codec;
use Grafida\Shell\Frame\FrameReader;
use Grafida\Shell\Frame\FrameWriter;
use Grafida\Shell\Frame\Message;
use Grafida\Shell\Frame\Ready;
use Grafida\Shell\Frame\RequestFrame;
use Grafida\Shell\Frame\ResponseFrame;
use Grafida\Shell\Request;
use Grafida\Shell\Response;

/**
 * A real worker process driven over real pipes, playing the shell's part: for an application's
 * tests (does my `bin/worker.php` work end to end?) and for this package's own.
 *
 * Every wait has a deadline. A worker that does not answer, or does not exit, is a failure here,
 * never a hung test run. stderr goes to a temporary file, so a chatty worker can never deadlock on a
 * full pipe.
 */
final class WorkerProcess
{
    public readonly Ready $ready;

    /** @var resource */
    private $process;

    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    /** stderr goes to this file (the child holds its own append-mode descriptor; we read by path) */
    private string $stderrFile;

    private ?int $exitCode = null;

    private int $nextId = 1;

    /**
     * Spawns the worker and waits for its `ready` frame.
     *
     * @param list<string>               $command e.g. `[PHP_BINARY, 'bin/worker.php']`
     * @param array<string, string>|null $env     the child's environment; null inherits ours
     *
     * @throws \RuntimeException when it cannot start, or sends anything but `ready` first
     */
    public function __construct(array $command, ?string $cwd = null, ?array $env = null, float $readyTimeout = 15.0)
    {
        $stderrFile = tempnam(sys_get_temp_dir(), 'shell-worker-stderr-');

        if ($stderrFile === false) {
            throw new \RuntimeException('Cannot create a temporary file for stderr');
        }

        $this->stderrFile = $stderrFile;
        $process          = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'a']], $pipes, $cwd, $env);

        if (!\is_resource($process)) {
            throw new \RuntimeException('Cannot start ' . implode(' ', $command));
        }

        $this->process = $process;
        $this->stdin   = $pipes[0];
        $this->stdout  = $pipes[1];

        $ready = $this->receive($readyTimeout);

        if (!$ready instanceof Ready) {
            $this->kill();

            throw new \RuntimeException('The worker did not announce itself with `ready`. stderr: ' . $this->stderr());
        }

        $this->ready = $ready;
    }

    public function __destruct()
    {
        $this->kill();
        @unlink($this->stderrFile);
    }

    /** The worker's process id, as the OS reports it. */
    public function pid(): int
    {
        return proc_get_status($this->process)['pid'];
    }

    /**
     * Sends a request and returns the response, checking that it answers this request's id.
     *
     * @throws \RuntimeException on timeout, EOF or a mismatched answer
     */
    public function request(Request $request, float $timeout = 30.0): Response
    {
        $id = $this->nextId++;
        $this->send(new RequestFrame($id, $request));
        $answer = $this->receive($timeout);

        if (!$answer instanceof ResponseFrame || $answer->id !== $id) {
            throw new \RuntimeException(sprintf('Expected the response to request %d. stderr: %s', $id, $this->stderr()));
        }

        return $answer->response;
    }

    /** Writes a frame, or raw bytes, to the worker's stdin. Ignores a pipe the worker has closed. */
    public function send(Message|string $frame): void
    {
        $bytes = \is_string($frame) ? $frame : Codec::encode($frame);

        try {
            (new FrameWriter($this->stdin))->writeBytes($bytes);
        } catch (\RuntimeException) {
            // The worker has stopped reading (it exited on a protocol error): its exit status and
            // stderr are what the caller checks.
        }
    }

    /**
     * The next frame from the worker's stdout, or null at EOF.
     *
     * @throws \RuntimeException when nothing arrives within `$timeout` seconds
     */
    public function receive(float $timeout = 30.0): ?Message
    {
        $read   = [$this->stdout];
        $none   = null;
        $ready  = stream_select($read, $none, $none, (int) $timeout, (int) (fmod($timeout, 1.0) * 1e6));

        if ($ready === 0 || $ready === false) {
            throw new \RuntimeException(sprintf('No frame from the worker within %.1fs. stderr: %s', $timeout, $this->stderr()));
        }

        return (new FrameReader($this->stdout))->read();
    }

    /** Closes the worker's stdin: EOF, which tells a worker to exit. */
    public function closeInput(): void
    {
        if (\is_resource($this->stdin)) {
            fclose($this->stdin);
        }
    }

    /**
     * Waits for the worker to exit and returns its status.
     *
     * @throws \RuntimeException when it is still running after `$timeout` seconds (it is then killed)
     */
    public function wait(float $timeout = 10.0): int
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            if ($this->exitCode !== null) {
                return $this->exitCode;
            }

            $status = proc_get_status($this->process);

            if (!$status['running']) {
                return $this->exitCode = $status['exitcode'];
            }

            if (microtime(true) > $deadline) {
                $this->kill();

                throw new \RuntimeException(sprintf('The worker did not exit within %.1fs (a hang). stderr: %s', $timeout, $this->stderr()));
            }

            usleep(5_000);
        }
    }

    /** Everything the worker has written to stderr so far. */
    public function stderr(): string
    {
        clearstatcache(true, $this->stderrFile);

        return (string) @file_get_contents($this->stderrFile);
    }

    public function kill(): void
    {
        if ($this->exitCode !== null || !\is_resource($this->process)) {
            return;
        }

        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process, 9);
        }

        $this->exitCode = -1;
        $this->closeInput();
        proc_close($this->process);
    }

    /**
     * Plays a conformance fixture into the worker the way a shell would — one request in flight —
     * and reports what happened. Compare the result with {@see Fixtures::workerOutcome()}.
     *
     * The worker must be fresh (only `ready` exchanged) and is finished afterwards.
     *
     * @return array{responses: list<ResponseFrame>, exit: int, stderr: string}
     */
    public function playFixture(string $name, float $timeout = 10.0): array
    {
        $expected = Fixtures::expected($name);
        $outcome  = Fixtures::workerOutcome($expected);
        $frames   = $expected['frames'];
        $answers  = [];

        foreach (Fixtures::chunks($name) as $i => $chunk) {
            $this->send($chunk);
            $type = $frames[$i]['header']['type'] ?? null;

            if ($type === 'request') {
                $answer = $this->receive($timeout);

                if ($answer instanceof ResponseFrame) {
                    $answers[] = $answer;
                }

                continue;
            }

            if ($type !== null) {
                break; // shutdown, or a frame a worker does not accept: it stops here
            }
        }

        if ($outcome['closeInput']) {
            $this->closeInput();
        }

        $exit = $this->wait($timeout);

        return ['responses' => $answers, 'exit' => $exit, 'stderr' => $this->stderr()];
    }
}
