<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell;

use Grafida\Shell\Frame\FrameReader;
use Grafida\Shell\Frame\FrameWriter;
use Grafida\Shell\Frame\Message;
use Grafida\Shell\Frame\ProtocolException;
use Grafida\Shell\Frame\Ready;
use Grafida\Shell\Frame\RequestFrame;
use Grafida\Shell\Frame\ResponseFrame;
use Grafida\Shell\Frame\Shutdown;

/**
 * The worker loop: announce `ready`, then answer one request at a time until EOF or `shutdown`.
 *
 * **stdout belongs to the frame stream.** One stray byte there desynchronises the shell, so:
 * `display_errors` goes to stderr; frames are written through a dedicated `php://stdout` handle,
 * flushed after every frame; and each handler call runs inside an output buffer whose contents go
 * to stderr, every line tagged `[stray-output]`. That buffer's callback does the diverting, so even
 * output flushed by PHP's shutdown after a fatal error lands on stderr, not in the stream.
 *
 * A handler that throws gets a `500`, and the worker keeps serving. Exit status (protocol/v1.md
 * §6): {@see EXIT_OK} after EOF or `shutdown`, {@see EXIT_PROTOCOL} after a protocol or session
 * error, and {@see EXIT_IO} when stdout cannot be written, i.e. the shell has gone.
 */
final class Worker
{
    public const int EXIT_OK       = 0;
    public const int EXIT_PROTOCOL = 65; // EX_DATAERR
    public const int EXIT_IO       = 74; // EX_IOERR

    /**
     * The environment variable through which the shell passes its body limit (the manifest's
     * `worker.maxBodyBytes`). Protocol v1 §2 requires both sides to use the same limit, and the
     * manifest is the one place it is configured, so this wins over {@see WorkerOptions::$maxBodyBytes}.
     */
    public const string BODY_LIMIT_ENV = 'SHELL_MAX_BODY_BYTES';

    private function __construct()
    {
    }

    /**
     * `$options` with the shell's body limit applied, when `$value` (the environment variable's
     * value, or false when unset) is a valid one: an integer in 1 … 2^53 − 1. An invalid value is
     * reported to `$error` and ignored.
     *
     * @param resource|null $error
     */
    public static function withShellBodyLimit(WorkerOptions $options, string|false $value, $error = null): WorkerOptions
    {
        if ($value === false || $value === '') {
            return $options;
        }

        if (!ctype_digit($value) || \strlen($value) > 16 || (int) $value < 1 || (int) $value > Protocol::MAX_INT) {
            if ($error !== null) {
                fwrite($error, sprintf("[worker] ignoring %s=%s: not an integer in 1 … 2^53 − 1\n", self::BODY_LIMIT_ENV, $value));
            }

            return $options;
        }

        return new WorkerOptions($options->app, $options->version, (int) $value);
    }

    /**
     * Runs the worker on the process's stdio and exits with its status. Call it as the last thing
     * the worker's entry point does.
     */
    public static function run(Handler $handler, WorkerOptions $options = new WorkerOptions()): never
    {
        ini_set('display_errors', 'stderr');
        ini_set('log_errors', '1');
        ini_set('html_errors', '0');

        // Anything the entry point already buffered must not reach stdout ahead of `ready`.
        $stderr = fopen('php://stderr', 'wb');
        \assert($stderr !== false);

        while (ob_get_level() > 0) {
            self::stray($stderr, (string) ob_get_clean());
        }

        $options = self::withShellBodyLimit($options, getenv(self::BODY_LIMIT_ENV), $stderr);

        $stdin  = fopen('php://stdin', 'rb');
        $stdout = fopen('php://stdout', 'wb');

        if ($stdin === false || $stdout === false) {
            fwrite($stderr, "[worker] cannot open stdio\n");

            exit(self::EXIT_IO);
        }

        exit(self::serve($handler, $options, $stdin, $stdout, $stderr));
    }

    /**
     * The loop itself, on any three streams; returns the exit status. {@see run()} is this on
     * stdio, and tests drive it on memory streams.
     *
     * @param resource $input  frames from the shell
     * @param resource $output frames to the shell; nothing else is ever written here
     * @param resource $error  log text
     */
    public static function serve(Handler $handler, WorkerOptions $options, $input, $output, $error): int
    {
        $reader = new FrameReader($input, $options->maxBodyBytes);
        $writer = new FrameWriter($output);

        try {
            $writer->write(new Ready(Protocol::VERSION, (int) getmypid(), $options->app, $options->version));

            while (true) {
                $message = $reader->read();

                if ($message === null || $message instanceof Shutdown) {
                    return self::EXIT_OK;
                }

                if (!$message instanceof RequestFrame) {
                    fwrite($error, sprintf("[protocol-error] session: a worker does not accept %s frames\n", self::typeOf($message)));

                    return self::EXIT_PROTOCOL;
                }

                $writer->writeBytes(self::answer($handler, $message, $options, $error));
            }
        } catch (ProtocolException $e) {
            fwrite($error, '[protocol-error] ' . $e->getMessage() . "\n");

            return self::EXIT_PROTOCOL;
        } catch (\RuntimeException $e) {
            fwrite($error, '[worker] ' . $e->getMessage() . "\n");

            return self::EXIT_IO;
        }
    }

    /**
     * Runs the handler with its output captured, and encodes its response. Whatever goes wrong,
     * this returns a valid response frame for the request's id.
     *
     * @param resource $error
     */
    private static function answer(Handler $handler, RequestFrame $frame, WorkerOptions $options, $error): string
    {
        $level = ob_get_level();
        ob_start(static function (string $buffer) use ($error): string {
            self::stray($error, $buffer);

            return '';
        });

        try {
            $response = $handler($frame->request);
        } catch (\Throwable $e) {
            fwrite($error, '[handler-error] ' . $e . "\n");
            $response = self::failure('internal_error');
        } finally {
            while (ob_get_level() > $level) {
                ob_end_flush();
            }
        }

        if (\strlen($response->body) > $options->maxBodyBytes) {
            fwrite($error, sprintf("[response-error] %d-byte body exceeds the %d-byte limit\n", \strlen($response->body), $options->maxBodyBytes));
            $response = self::failure('response_too_large');
        }

        try {
            return Frame\Codec::encode(new ResponseFrame($frame->id, $response));
        } catch (ProtocolException $e) {
            fwrite($error, '[response-error] ' . $e->getMessage() . "\n");

            return Frame\Codec::encode(new ResponseFrame($frame->id, self::failure('bad_response')));
        }
    }

    private static function failure(string $code): Response
    {
        return new Response('{"error":"' . $code . '"}', 500, [
            'content-type'  => 'application/json; charset=utf-8',
            'cache-control' => 'no-store',
        ]);
    }

    /** @param resource $error */
    private static function stray($error, string $output): void
    {
        if ($output === '') {
            return;
        }

        foreach (explode("\n", rtrim($output, "\n")) as $line) {
            fwrite($error, '[stray-output] ' . $line . "\n");
        }
    }

    private static function typeOf(Message $message): string
    {
        return match (true) {
            $message instanceof Ready         => 'ready',
            $message instanceof ResponseFrame => 'response',
            default                           => $message::class,
        };
    }
}
