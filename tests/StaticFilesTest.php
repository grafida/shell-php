<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Tests;

use Grafida\Shell\MimeTypes;
use Grafida\Shell\Request;
use Grafida\Shell\ShellScript;
use Grafida\Shell\StaticFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class StaticFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shell-php-static-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/first/css', 0o777, true);
        mkdir($this->dir . '/second/img', 0o777, true);
        mkdir($this->dir . '/first/dir.css');
        file_put_contents($this->dir . '/first/css/app.css', 'body{}');
        file_put_contents($this->dir . '/second/css-only-here.css', 'second');
        file_put_contents($this->dir . '/first/shared.txt', 'from first');
        file_put_contents($this->dir . '/second/shared.txt', 'from second');
        file_put_contents($this->dir . '/second/img/a b €.png', "\x89PNG");
        file_put_contents($this->dir . '/secret.txt', 'outside every root');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($it as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->dir);
    }

    private function files(): StaticFiles
    {
        return new StaticFiles([$this->dir . '/first', $this->dir . '/second/']);
    }

    public function testServesFromTheFirstRootThatHasTheFile(): void
    {
        $css = $this->files()->find(new Request('GET', 'shell://app/css/app.css'));
        self::assertNotNull($css);
        self::assertSame('body{}', $css->body);
        self::assertSame('text/css; charset=utf-8', $css->header('content-type'));

        self::assertSame('from first', $this->files()->find(new Request('GET', 'shell://app/shared.txt'))?->body);
        self::assertSame('second', $this->files()->find(new Request('GET', 'shell://app/css-only-here.css?v=3'))?->body);
    }

    public function testPercentEncodedPathsAreDecoded(): void
    {
        $png = $this->files()->find(new Request('GET', 'shell://app/img/a%20b%20%E2%82%AC.png'));

        self::assertSame("\x89PNG", $png?->body);
        self::assertSame('image/png', $png->header('content-type'));
    }

    public function testHeadHasHeadersButNoBody(): void
    {
        $head = $this->files()->find(new Request('HEAD', 'shell://app/css/app.css'));

        self::assertSame('', $head?->body);
        self::assertSame('text/css; charset=utf-8', $head->header('content-type'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function misses(): iterable
    {
        yield 'absent file'          => ['GET', 'shell://app/nope.js'];
        yield 'root'                 => ['GET', 'shell://app/'];
        yield 'directory'            => ['GET', 'shell://app/css'];
        yield 'directory named .css' => ['GET', 'shell://app/dir.css'];
        yield 'POST'                 => ['POST', 'shell://app/css/app.css'];
        yield 'dot-dot'              => ['GET', 'shell://app/../secret.txt'];
        yield 'encoded dot-dot'      => ['GET', 'shell://app/%2e%2e/secret.txt'];
        yield 'encoded slash dot-dot' => ['GET', 'shell://app/css%2F..%2F..%2Fsecret.txt'];
        yield 'backslash'            => ['GET', 'shell://app/css%5C..%5C..%5Csecret.txt'];
        yield 'NUL'                  => ['GET', 'shell://app/css/app.css%00.png'];
    }

    #[DataProvider('misses')]
    public function testMissesAndEscapesAnswerNull(string $method, string $url): void
    {
        self::assertNull($this->files()->find(new Request($method, $url)));
    }

    public function testAsAHandlerAMissIsA404(): void
    {
        $files = $this->files();

        self::assertSame(404, $files(new Request('GET', 'shell://app/nope'))->status);
        self::assertSame(200, $files(new Request('GET', 'shell://app/shared.txt'))->status);
    }

    public function testRelativePathRules(): void
    {
        self::assertSame('a/b.css', StaticFiles::relativePath('//a/./b.css'));
        self::assertNull(StaticFiles::relativePath('/a/../b.css'));
        self::assertNull(StaticFiles::relativePath('/'));
        self::assertNull(StaticFiles::relativePath(''));
    }

    /** Boson's provider could not do this; the worker runs from a PHAR in production. */
    #[RequiresPhpExtension('phar')]
    public function testServesFromAPhar(): void
    {
        if ((bool) \ini_get('phar.readonly')) {
            // Building a PHAR needs phar.readonly=0; re-run this one test in a child PHP that has it.
            $cmd = [\PHP_BINARY, '-d', 'phar.readonly=0', __DIR__ . '/Fixtures/phar-static.php', $this->dir];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            \assert(\is_resource($proc));
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            self::assertSame(0, proc_close($proc), $err);
            self::assertSame('ok', trim($out), $err);

            return;
        }

        require_once __DIR__ . '/Fixtures/phar-static.php';
        self::assertSame('ok', \Grafida\Shell\Tests\Fixtures\pharStaticCheck($this->dir));
    }

    public function testMimeTypes(): void
    {
        self::assertSame('text/javascript; charset=utf-8', MimeTypes::contentType('x.JS'));
        self::assertSame('application/json; charset=utf-8', MimeTypes::contentType('a.map'));
        self::assertSame('image/svg+xml; charset=utf-8', MimeTypes::contentType('a.svg'));
        self::assertSame('font/woff2', MimeTypes::contentType('a.woff2'));
        // Never text/html for an unknown type: an unknown file is never rendered as a page.
        self::assertSame('application/octet-stream', MimeTypes::contentType('a.ts'));
        self::assertSame('application/octet-stream', MimeTypes::contentType('noextension'));
    }

    public function testShellScriptShipsWithThePackage(): void
    {
        $response = ShellScript::response();

        self::assertStringContainsString('GrafidaShell', $response->body);
        self::assertSame('text/javascript; charset=utf-8', $response->header('content-type'));
    }
}
