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
 * Where `shell.js`, the page-side helper for the shell's `_native/*` endpoints, lives in this
 * package, so an app can serve it (e.g. from a route, or by copying it into its assets at build
 * time) without hard-coding a vendor path.
 */
final class ShellScript
{
    private function __construct()
    {
    }

    /** Absolute path to `shell.js`; inside a PHAR this is a `phar://` path. */
    public static function path(): string
    {
        return \dirname(__DIR__) . '/resources/shell.js';
    }

    /** `shell.js`, ready to serve. */
    public static function response(): Response
    {
        return Response::file(self::path(), 'text/javascript; charset=utf-8');
    }
}
