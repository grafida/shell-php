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
 * Content types for the files a desktop web app ships, by extension.
 *
 * Deliberately a short, closed table rather than a full MIME database: these are the app's own
 * assets. Text types carry `charset=utf-8`. An unknown extension is `application/octet-stream` —
 * never `text/html`, so a file of unknown type is never rendered as a page.
 */
final class MimeTypes
{
    public const string DEFAULT = 'application/octet-stream';

    private const array TYPES = [
        'html'  => 'text/html',
        'htm'   => 'text/html',
        'css'   => 'text/css',
        'js'    => 'text/javascript',
        'mjs'   => 'text/javascript',
        'json'  => 'application/json',
        'map'   => 'application/json',
        'webmanifest' => 'application/manifest+json',
        'xml'   => 'application/xml',
        'txt'   => 'text/plain',
        'md'    => 'text/markdown',
        'csv'   => 'text/csv',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'ico'   => 'image/x-icon',
        'bmp'   => 'image/bmp',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'wasm'  => 'application/wasm',
        'pdf'   => 'application/pdf',
        'mp3'   => 'audio/mpeg',
        'ogg'   => 'audio/ogg',
        'wav'   => 'audio/wav',
        'mp4'   => 'video/mp4',
        'webm'  => 'video/webm',
    ];

    private const array TEXT = ['application/json', 'application/manifest+json', 'application/xml', 'image/svg+xml'];

    private function __construct()
    {
    }

    /** The bare MIME type for a path's extension (case-insensitive). */
    public static function type(string $path): string
    {
        return self::TYPES[strtolower(pathinfo($path, \PATHINFO_EXTENSION))] ?? self::DEFAULT;
    }

    /** The `content-type` value for a path: the MIME type, plus `charset=utf-8` for text. */
    public static function contentType(string $path): string
    {
        $type = self::type($path);

        return str_starts_with($type, 'text/') || \in_array($type, self::TEXT, true)
            ? $type . '; charset=utf-8'
            : $type;
    }
}
