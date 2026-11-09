<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Frame;

use Grafida\Shell\Headers;
use Grafida\Shell\Protocol;
use Grafida\Shell\Request;
use Grafida\Shell\Response;

/**
 * Frame header validation and frame encoding (protocol/v1.md §2–§4).
 *
 * There is one set of rules, and it is the decoder's: {@see encode()} validates the header it
 * builds by running it back through {@see decodeHeader()}, so this side can never emit a frame a
 * conforming receiver would reject.
 */
final class Codec
{
    private const string TOKEN      = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';
    private const string LOWER_NAME = '/^[!#$%&\'*+\-.^_`|~0-9a-z]+$/D';

    private function __construct()
    {
    }

    /**
     * Encodes a complete frame: magic, header length, header, body length, body.
     *
     * @throws ProtocolException when the message breaks the spec (`bad_header`, `header_too_large`)
     */
    public static function encode(Message $message): string
    {
        $header = self::normalForm($message);
        $body   = match (true) {
            $message instanceof RequestFrame  => $message->request->body,
            $message instanceof ResponseFrame => $message->response->body,
            default                           => '',
        };

        // Headers become a JSON object even when empty or numerically named ("0" would otherwise
        // make PHP emit a JSON array).
        if (isset($header['headers']) && \is_array($header['headers'])) {
            $header['headers'] = (object) $header['headers'];
        }

        try {
            $json = json_encode($header, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new ProtocolException(ProtocolException::BAD_HEADER, 'cannot encode the header: ' . $e->getMessage(), $e);
        }

        if (\strlen($json) > Protocol::MAX_HEADER_BYTES) {
            throw new ProtocolException(
                ProtocolException::HEADER_TOO_LARGE,
                sprintf('%d bytes > %d', \strlen($json), Protocol::MAX_HEADER_BYTES),
            );
        }

        self::decodeHeader($json);

        return Protocol::MAGIC . pack('V', \strlen($json)) . $json . pack('P', \strlen($body)) . $body;
    }

    /**
     * Parses and validates a header (steps 4–5 of the spec's order of checks). A request or
     * response comes back with an empty body; the caller attaches the body with {@see withBody()}.
     *
     * @throws ProtocolException `bad_json`, `bad_header` or `unknown_type`
     */
    public static function decodeHeader(string $json): Message
    {
        try {
            // Objects stay objects, so `{}` and `[]` can be told apart; huge integers become strings
            // (and are then rejected) instead of silently turning into floats.
            $value = json_decode($json, false, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new ProtocolException(ProtocolException::BAD_JSON, $e->getMessage(), $e);
        }

        if (!$value instanceof \stdClass) {
            throw self::bad('the header is not a JSON object');
        }

        $members = get_object_vars($value);
        $type    = $members['type'] ?? null;

        if (!\is_string($type)) {
            throw self::bad('`type` is missing or not a string');
        }

        return match ($type) {
            'ready' => new Ready(
                self::int($members, 'protocol', 1, Protocol::MAX_INT),
                self::int($members, 'pid', 0, Protocol::MAX_INT),
                self::optionalString($members, 'app'),
                self::optionalString($members, 'version'),
            ),
            'request'  => self::request($members),
            'response' => new ResponseFrame(
                self::int($members, 'id', 0, Protocol::MAX_INT),
                new Response('', self::int($members, 'status', 100, 599), self::headerMap($members)),
            ),
            'shutdown' => new Shutdown(),
            default    => throw new ProtocolException(ProtocolException::UNKNOWN_TYPE, sprintf('`%s`', $type)),
        };
    }

    /** Whether frames of this type may carry a body (only requests and responses do). */
    public static function allowsBody(Message $message): bool
    {
        return $message instanceof RequestFrame || $message instanceof ResponseFrame;
    }

    /** The same message carrying `$body`; a message type without a body is returned unchanged. */
    public static function withBody(Message $message, string $body): Message
    {
        return match (true) {
            $message instanceof RequestFrame => new RequestFrame($message->id, new Request(
                $message->request->method,
                $message->request->url,
                $message->request->headers,
                $body,
            )),
            $message instanceof ResponseFrame => new ResponseFrame($message->id, $message->response->withBody($body)),
            default                           => $message,
        };
    }

    /**
     * The header in normal form (spec §8): only the members the spec defines, optional ones only
     * when set. This is what goes on the wire, and what the fixtures' `header` member holds.
     *
     * @return array<string, mixed>
     */
    public static function normalForm(Message $message): array
    {
        return match (true) {
            $message instanceof Ready => ['type' => 'ready', 'protocol' => $message->protocol, 'pid' => $message->pid]
                + ($message->app !== null ? ['app' => $message->app] : [])
                + ($message->version !== null ? ['version' => $message->version] : []),
            $message instanceof RequestFrame => [
                'type'    => 'request',
                'id'      => $message->id,
                'method'  => $message->request->method,
                'url'     => $message->request->url,
                'headers' => $message->request->headers->toArray(),
            ],
            $message instanceof ResponseFrame => [
                'type'    => 'response',
                'id'      => $message->id,
                'status'  => $message->response->status,
                'headers' => $message->response->headers->toArray(),
            ],
            $message instanceof Shutdown => ['type' => 'shutdown'],
            default                      => throw new \LogicException('Unknown message ' . $message::class),
        };
    }

    /** @param array<array-key, mixed> $members */
    private static function request(array $members): RequestFrame
    {
        $id     = self::int($members, 'id', 0, Protocol::MAX_INT);
        $method = self::string($members, 'method');

        if (preg_match(self::TOKEN, $method) !== 1) {
            throw self::bad('`method` is not an HTTP token');
        }

        $url = self::string($members, 'url');

        if ($url === '') {
            throw self::bad('`url` is empty');
        }

        return new RequestFrame($id, new Request($method, $url, self::headerMap($members)));
    }

    /** @param array<array-key, mixed> $members */
    private static function headerMap(array $members): Headers
    {
        $headers = $members['headers'] ?? null;

        if (!$headers instanceof \stdClass) {
            throw self::bad('`headers` is missing or not an object');
        }

        $map = [];

        foreach (get_object_vars($headers) as $name => $values) {
            // get_object_vars() turns a numeric member name into an int key.
            $name = (string) $name;

            if (preg_match(self::LOWER_NAME, $name) !== 1) {
                throw self::bad(sprintf('header name `%s` is not a lower-case token', $name));
            }

            if (!\is_array($values) || $values === []) {
                throw self::bad(sprintf('header `%s` is not a non-empty array', $name));
            }

            foreach ($values as $value) {
                if (!\is_string($value)) {
                    throw self::bad(sprintf('header `%s` has a non-string value', $name));
                }

                if (strpbrk($value, "\r\n\0") !== false) {
                    throw self::bad(sprintf('header `%s` has CR, LF or NUL in a value', $name));
                }

                $map[$name][] = $value;
            }
        }

        return new Headers($map);
    }

    /** @param array<array-key, mixed> $members */
    private static function int(array $members, string $key, int $min, int $max): int
    {
        $value = $members[$key] ?? null;

        // A JSON integer literal decodes to int; 17.0 and 1e2 to float; beyond PHP_INT_MAX to
        // string (JSON_BIGINT_AS_STRING). Only the first is an integer on the wire.
        if (!\is_int($value) || $value < $min || $value > $max) {
            throw self::bad(sprintf('`%s` is missing or not an integer in %d..%d', $key, $min, $max));
        }

        return $value;
    }

    /** @param array<array-key, mixed> $members */
    private static function string(array $members, string $key): string
    {
        $value = $members[$key] ?? null;

        if (!\is_string($value)) {
            throw self::bad(sprintf('`%s` is missing or not a string', $key));
        }

        return $value;
    }

    /** @param array<array-key, mixed> $members */
    private static function optionalString(array $members, string $key): ?string
    {
        if (!\array_key_exists($key, $members)) {
            return null;
        }

        if (!\is_string($members[$key])) {
            throw self::bad(sprintf('`%s` is not a string', $key));
        }

        return $members[$key];
    }

    private static function bad(string $message): ProtocolException
    {
        return new ProtocolException(ProtocolException::BAD_HEADER, $message);
    }
}
