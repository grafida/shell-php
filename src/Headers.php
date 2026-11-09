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
 * An immutable, case-insensitive HTTP header map that keeps **every** value of a repeated header.
 *
 * Stored as lower-case name → list of values, which is exactly how the wire protocol carries them,
 * so repeated headers survive end to end. Iterating yields one `name => value` pair **per value**
 * (a repeated header yields its name more than once), which lets code written against a
 * single-valued map keep working.
 *
 * Names must be HTTP tokens and values must not contain CR, LF or NUL; anything else is refused
 * here, at construction, rather than when the frame is encoded.
 *
 * @implements \IteratorAggregate<string, string>
 */
final readonly class Headers implements \IteratorAggregate, \Countable
{
    /**
     * Lower-case name => values. A numeric name such as "0" becomes an int key, as in any PHP
     * array; every accessor casts it back.
     *
     * @var array<array-key, non-empty-list<string>>
     */
    private array $map;

    /**
     * @param iterable<array-key, string|\Stringable|iterable<string|\Stringable>> $headers name => value or values;
     *                                                                                    names are lower-cased and
     *                                                                                    values of names that differ
     *                                                                                    only in case are merged
     *
     * @throws \InvalidArgumentException for a name that is not a token, or a value with CR, LF or NUL
     */
    public function __construct(iterable $headers = [])
    {
        $map = [];

        foreach ($headers as $name => $values) {
            $name = self::name((string) $name);

            foreach (is_iterable($values) ? $values : [$values] as $value) {
                if (!\is_string($value) && !$value instanceof \Stringable) {
                    throw new \InvalidArgumentException(sprintf('Header "%s" has a value that is not a string', $name));
                }

                $map[$name][] = self::value($name, (string) $value);
            }
        }

        $this->map = $map;
    }

    /** The first value of a header, or `$default` when it is absent. */
    public function get(string $name, ?string $default = null): ?string
    {
        return $this->map[strtolower($name)][0] ?? $default;
    }

    /** Every value of a header joined by `", "` (RFC 9110 §5.3), or null when it is absent. */
    public function line(string $name): ?string
    {
        $values = $this->map[strtolower($name)] ?? null;

        return $values === null ? null : implode(', ', $values);
    }

    /** @return list<string> every value of a header, in order; empty when it is absent */
    public function all(string $name): array
    {
        return $this->map[strtolower($name)] ?? [];
    }

    public function has(string $name): bool
    {
        return isset($this->map[strtolower($name)]);
    }

    /**
     * A copy with the header replaced by `$values`.
     *
     * @param string|list<string> $values
     */
    public function with(string $name, string|array $values): self
    {
        $map                   = $this->map;
        $map[strtolower($name)] = is_array($values) ? $values : [$values];

        return new self($map);
    }

    /** A copy with one more value appended to the header. */
    public function withAdded(string $name, string $value): self
    {
        $map                     = $this->map;
        $map[strtolower($name)][] = $value;

        return new self($map);
    }

    /** A copy without the header. */
    public function without(string $name): self
    {
        $map = $this->map;
        unset($map[strtolower($name)]);

        return new self($map);
    }

    /**
     * Lower-case name => values, the wire form. A numeric name comes back as an int key; encode
     * it as a JSON object (`(object)`), never as a list.
     *
     * @return array<array-key, non-empty-list<string>>
     */
    public function toArray(): array
    {
        return $this->map;
    }

    /** @return \Generator<string, string> one pair per value */
    public function getIterator(): \Generator
    {
        foreach ($this->map as $name => $values) {
            foreach ($values as $value) {
                yield \strval($name) => $value;
            }
        }
    }

    /** The number of distinct header names. */
    public function count(): int
    {
        return \count($this->map);
    }

    private static function name(string $name): string
    {
        $lower = strtolower($name);

        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9a-z]+$/D', $lower) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid header name "%s"', addcslashes($name, "\0..\37")));
        }

        return $lower;
    }

    private static function value(string $name, string $value): string
    {
        if (strpbrk($value, "\r\n\0") !== false) {
            throw new \InvalidArgumentException(sprintf('Header "%s" has CR, LF or NUL in its value', $name));
        }

        return $value;
    }
}
