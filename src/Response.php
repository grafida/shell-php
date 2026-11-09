<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell;

/**
 * An immutable response to a {@see Request}.
 *
 * The shell sends the body as-is and derives its length from the frame, so no `content-length`
 * header is needed.
 */
final readonly class Response
{
    public Headers $headers;

    /**
     * @param Headers|iterable<array-key, string|\Stringable|iterable<string|\Stringable>> $headers
     *
     * @throws \InvalidArgumentException for a status outside 100–599, or an invalid header
     */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        Headers|iterable $headers = [],
    ) {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException(sprintf('Invalid HTTP status %d', $status));
        }

        $this->headers = $headers instanceof Headers ? $headers : new Headers($headers);
    }

    /**
     * A JSON response (`application/json; charset=utf-8`), slashes and Unicode unescaped.
     *
     * @param iterable<array-key, string|\Stringable|iterable<string|\Stringable>> $headers extra headers
     *
     * @throws \JsonException when `$data` cannot be encoded
     */
    public static function json(mixed $data, int $status = 200, iterable $headers = []): self
    {
        $body = json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return (new self($body, $status, $headers))
            ->withHeader('content-type', 'application/json; charset=utf-8');
    }

    /** @param iterable<array-key, string|\Stringable|iterable<string|\Stringable>> $headers extra headers */
    public static function html(string $html, int $status = 200, iterable $headers = []): self
    {
        return (new self($html, $status, $headers))->withHeader('content-type', 'text/html; charset=utf-8');
    }

    /** @param iterable<array-key, string|\Stringable|iterable<string|\Stringable>> $headers extra headers */
    public static function text(string $text, int $status = 200, iterable $headers = []): self
    {
        return (new self($text, $status, $headers))->withHeader('content-type', 'text/plain; charset=utf-8');
    }

    /**
     * A file's contents, typed by its extension unless `$contentType` says otherwise. Works for
     * `phar://` paths too.
     *
     * @param iterable<array-key, string|\Stringable|iterable<string|\Stringable>> $headers extra headers
     *
     * @throws \RuntimeException when the file cannot be read
     */
    public static function file(string $path, ?string $contentType = null, iterable $headers = []): self
    {
        $body = is_file($path) ? @file_get_contents($path) : false;

        if ($body === false) {
            throw new \RuntimeException(sprintf('Cannot read %s', $path));
        }

        return (new self($body, 200, $headers))
            ->withHeader('content-type', $contentType ?? MimeTypes::contentType($path));
    }

    /** @param string|list<string> $values */
    public function withHeader(string $name, string|array $values): self
    {
        return new self($this->body, $this->status, $this->headers->with($name, $values));
    }

    public function withAddedHeader(string $name, string $value): self
    {
        return new self($this->body, $this->status, $this->headers->withAdded($name, $value));
    }

    public function withStatus(int $status): self
    {
        return new self($this->body, $status, $this->headers);
    }

    public function withBody(string $body): self
    {
        return new self($body, $this->status, $this->headers);
    }

    /** The first value of a response header, or null. */
    public function header(string $name): ?string
    {
        return $this->headers->get($name);
    }
}
