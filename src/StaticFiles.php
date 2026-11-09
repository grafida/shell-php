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
 * Serves files from one or more document roots, which may be plain directories or `phar://` paths.
 *
 * The roots are searched in order and the first match wins. The URL path is percent-decoded and
 * resolved **lexically**: `realpath()` does not work inside a PHAR, so containment is not checked
 * after the fact. Instead, any `..` segment, backslash or NUL refuses the request outright. Empty
 * and `.` segments are dropped. Only regular files are served, never directory listings. Symbolic
 * links inside a root are followed; a root is trusted content.
 *
 * {@see find()} answers null on a miss, so an app can fall through to its own routing (a
 * single-page app's shell, for instance). Used directly as a {@see Handler}, a miss is a `404`.
 */
final readonly class StaticFiles implements Handler
{
    /** @var list<string> */
    private array $roots;

    /** @param string|list<string> $roots document roots, searched in order */
    public function __construct(string|array $roots)
    {
        $this->roots = array_map(
            static fn (string $root): string => rtrim($root, '/\\'),
            \is_string($roots) ? [$roots] : $roots,
        );
    }

    /**
     * The file a GET or HEAD request names, or null when no root has it (or the method or path is
     * not one this handler serves).
     */
    public function find(Request $request): ?Response
    {
        $method = strtoupper($request->method);

        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }

        $relative = self::relativePath($request->path());

        if ($relative === null) {
            return null;
        }

        foreach ($this->roots as $root) {
            $file = $root . '/' . $relative;

            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $response = Response::file($file);

            return $method === 'HEAD' ? $response->withBody('') : $response;
        }

        return null;
    }

    public function __invoke(Request $request): Response
    {
        return $this->find($request) ?? Response::text('Not found', 404);
    }

    /**
     * The URL path as a root-relative file path, or null when it names nothing servable.
     *
     * Public so that the rules can be tested on their own.
     */
    public static function relativePath(string $urlPath): ?string
    {
        $decoded = rawurldecode($urlPath);

        if (str_contains($decoded, "\0") || str_contains($decoded, '\\')) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }
}
