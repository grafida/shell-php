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
 * A request the webview made to the app's custom scheme, as the shell forwarded it.
 *
 * The URL is kept **verbatim**: the wire protocol never decodes or normalises it, and neither does
 * this class. {@see path()} returns the path as sent (percent-escapes intact); {@see query()} decodes
 * one query parameter the way `parse_str()` does.
 */
final readonly class Request
{
    public Headers $headers;

    /**
     * @param string                                                                     $method the HTTP method, case preserved
     * @param string                                                                     $url    the absolute URL, e.g. `boson://app/api/sites?x=1`
     * @param Headers|iterable<array-key, string|\Stringable|iterable<string|\Stringable>> $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        Headers|iterable $headers = [],
        public string $body = '',
    ) {
        $this->headers = $headers instanceof Headers ? $headers : new Headers($headers);
    }

    /** The URL's path as sent (still percent-encoded); `/` when the URL has none. */
    public function path(): string
    {
        $path = parse_url($this->url, \PHP_URL_PATH);

        return \is_string($path) && $path !== '' ? $path : '/';
    }

    /** The URL's raw query string, without the `?`; empty when there is none. */
    public function queryString(): string
    {
        $query = parse_url($this->url, \PHP_URL_QUERY);

        return \is_string($query) ? $query : '';
    }

    /**
     * Every query parameter, decoded by `parse_str()` (so `a[]=1` is an array).
     *
     * @return array<array-key, mixed>
     */
    public function queryParams(): array
    {
        parse_str($this->queryString(), $params);

        return $params;
    }

    /**
     * One query parameter, decoded; the first element when it was sent as an array, `$default`
     * when it is absent.
     */
    public function query(string $name, ?string $default = null): ?string
    {
        $value = $this->queryParams()[$name] ?? null;

        if (\is_array($value)) {
            $value = reset($value);
        }

        return \is_string($value) ? $value : $default;
    }

    /** The first value of a request header, or null. */
    public function header(string $name): ?string
    {
        return $this->headers->get($name);
    }
}
