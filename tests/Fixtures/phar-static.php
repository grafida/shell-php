<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 *
 * Builds a PHAR with a document root inside and checks StaticFiles serves from it. Run in-process
 * when phar.readonly is off, or as `php -d phar.readonly=0 phar-static.php <tmpdir>` otherwise.
 */

declare(strict_types=1);

namespace Grafida\Shell\Tests\Fixtures;

use Grafida\Shell\Request;
use Grafida\Shell\StaticFiles;

function pharStaticCheck(string $tmp): string
{
    $file = $tmp . '/assets.phar';
    $phar = new \Phar($file);
    $phar->addFromString('public/css/app.css', 'from phar');
    $phar->setStub('<?php __HALT_COMPILER();');
    unset($phar);

    $files = new StaticFiles('phar://' . $file . '/public');
    $hit   = $files->find(new Request('GET', 'boson://app/css/app.css'));
    $miss  = $files->find(new Request('GET', 'boson://app/../public/css/app.css'));

    return $hit?->body === 'from phar' && $hit->header('content-type') === 'text/css; charset=utf-8' && $miss === null
        ? 'ok'
        : 'failed: ' . var_export([$hit?->body, $hit?->header('content-type'), $miss], true);
}

$script = $_SERVER['SCRIPT_FILENAME'] ?? null;

if (\is_string($script) && realpath($script) === __FILE__) {
    require \dirname(__DIR__, 2) . '/vendor/autoload.php';

    echo pharStaticCheck($argv[1] ?? sys_get_temp_dir()), "\n";
}
