<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Tests\Conformance;

use Grafida\Shell\Protocol;
use Grafida\Shell\Testing\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The vendored fixtures are exactly grafida/shell's: every file is listed in its `SHA256SUMS` with
 * the hash it has upstream, and nothing unlisted has crept in. (CI also compares the copy with
 * grafida/shell at `protocol/FIXTURES_REF`.)
 */
final class FixtureManifestTest extends TestCase
{
    public function testEveryVendoredFileMatchesTheManifest(): void
    {
        $dir    = Fixtures::dir();
        $listed = [];

        $lines = file($dir . '/SHA256SUMS', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        foreach ($lines as $line) {
            [$digest, $file] = explode('  ', $line, 2);
            self::assertFileExists($dir . '/' . $file);
            self::assertSame($digest, hash_file('sha256', $dir . '/' . $file), "$file differs from grafida/shell; never edit a vendored fixture");
            $listed[] = $file;
        }

        $files = scandir($dir);
        self::assertIsArray($files);
        $present = array_values(array_filter(
            $files,
            static fn (string $f): bool => str_ends_with($f, '.bin') || str_ends_with($f, '.json'),
        ));

        sort($listed);
        sort($present);
        self::assertSame($listed, $present, 'SHA256SUMS does not list exactly the vendored fixture files');
        self::assertGreaterThan(50, \count(Fixtures::names()));
    }

    public function testTheVendoredCopyNamesTheShellCommitItCameFrom(): void
    {
        $ref = trim((string) file_get_contents(\dirname(Fixtures::dir()) . '/FIXTURES_REF'));

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $ref);
    }

    public function testProtocolConstantsMatchTheSpec(): void
    {
        self::assertSame('GSF1', Protocol::MAGIC);
        self::assertSame(1, Protocol::VERSION);
        self::assertSame(1048576, Protocol::MAX_HEADER_BYTES);
        self::assertSame(134217728, Protocol::DEFAULT_MAX_BODY_BYTES);
        self::assertSame(9007199254740991, Protocol::MAX_INT);
    }
}
