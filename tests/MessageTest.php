<?php

/**
 * Grafida Shell — PHP worker runtime.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\Shell\Tests;

use Grafida\Shell\Headers;
use Grafida\Shell\Request;
use Grafida\Shell\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Headers, Request and Response: the value types an app is written against. */
final class MessageTest extends TestCase
{
    public function testHeadersKeepEveryValueOfARepeatedHeader(): void
    {
        $headers = new Headers(['X-Multi' => ['one', 'two'], 'x-multi' => 'three', 'Accept' => 'text/html']);

        self::assertSame(['x-multi' => ['one', 'two', 'three'], 'accept' => ['text/html']], $headers->toArray());
        self::assertSame('one', $headers->get('X-MULTI'));
        self::assertSame('one, two, three', $headers->line('x-multi'));
        self::assertSame(['one', 'two', 'three'], $headers->all('x-multi'));
        self::assertSame([], $headers->all('absent'));
        self::assertNull($headers->line('absent'));
        self::assertSame('fallback', $headers->get('absent', 'fallback'));
        self::assertCount(2, $headers);
    }

    /** Iterating yields one pair per value, so code written for a single-valued map keeps working. */
    public function testIterationYieldsOnePairPerValue(): void
    {
        $pairs = [];

        foreach (new Headers(['x-multi' => ['one', 'two'], 'accept' => 'text/html']) as $name => $value) {
            $pairs[] = [$name, $value];
        }

        self::assertSame([['x-multi', 'one'], ['x-multi', 'two'], ['accept', 'text/html']], $pairs);
    }

    public function testHeadersAreImmutable(): void
    {
        $a = new Headers(['a' => '1']);
        $b = $a->with('B', ['2', '3'])->withAdded('a', '4')->without('missing');

        self::assertSame(['a' => ['1']], $a->toArray());
        self::assertSame(['a' => ['1', '4'], 'b' => ['2', '3']], $b->toArray());
        self::assertSame(['b' => ['2', '3']], $b->without('A')->toArray());
    }

    public function testANumericHeaderNameStaysAString(): void
    {
        $pairs = iterator_to_array(new Headers(['0' => 'zero']));

        self::assertSame(['0' => 'zero'], $pairs);
        self::assertSame('zero', (new Headers(['0' => 'zero']))->get('0'));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidHeaders(): iterable
    {
        yield 'space in name'   => [['x y' => '1']];
        yield 'empty name'      => [['' => '1']];
        yield 'colon in name'   => [['x:y' => '1']];
        yield 'non-ASCII name'  => [['x-é' => '1']];
        yield 'CRLF in value'   => [['x' => "1\r\nx-injected: 1"]];
        yield 'LF in value'     => [['x' => "1\n2"]];
        yield 'NUL in value'    => [['x' => "1\0"]];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('invalidHeaders')]
    public function testInvalidHeadersAreRefusedAtConstruction(array $headers): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Headers($headers);
    }

    public function testRequestKeepsTheBosonPositionalSignature(): void
    {
        $request = new Request('POST', 'boson://app/api/drafts/3', ['Content-Type' => 'application/json'], '{"a":1}');

        self::assertSame('POST', $request->method);
        self::assertSame('boson://app/api/drafts/3', $request->url);
        self::assertSame('{"a":1}', $request->body);
        self::assertSame('application/json', $request->header('content-type'));
        self::assertSame('/api/drafts/3', $request->path());
    }

    public function testRequestPathIsVerbatimAndNeverEmpty(): void
    {
        self::assertSame('/', (new Request('GET', 'boson://app'))->path());
        self::assertSame('/', (new Request('GET', 'boson://app/'))->path());
        self::assertSame('/api/help/page/%CE%B5', (new Request('GET', 'boson://app/api/help/page/%CE%B5?x=1'))->path());
        self::assertSame('/api/help/page/Ελληνικά', (new Request('GET', 'boson://app/api/help/page/Ελληνικά'))->path());
        self::assertSame('patch', (new Request('patch', 'boson://app/'))->method);
    }

    /** Same semantics as Boson's Query::get(): parse_str() decoding, first element of an array. */
    public function testQueryParametersAreDecodedLikeParseStr(): void
    {
        $request = new Request('GET', 'boson://app/api/sites/1/media?path=a%20b%2Fc&x=%E2%82%AC+%25&list[]=first&list[]=second&empty=');

        self::assertSame('a b/c', $request->query('path'));
        self::assertSame('€ %', $request->query('x'));
        self::assertSame('first', $request->query('list'));
        self::assertSame('', $request->query('empty', 'default'));
        self::assertNull($request->query('absent'));
        self::assertSame('default', $request->query('absent', 'default'));
        self::assertSame('path=a%20b%2Fc&x=%E2%82%AC+%25&list[]=first&list[]=second&empty=', $request->queryString());
        self::assertSame('', (new Request('GET', 'boson://app/'))->queryString());
        self::assertSame([], (new Request('GET', 'boson://app/'))->queryParams());
    }

    public function testResponseKeepsTheBosonPositionalSignature(): void
    {
        $response = new Response('body', 201, ['Content-Type' => 'text/plain', 'X-Multi' => ['a', 'b']]);

        self::assertSame('body', $response->body);
        self::assertSame(201, $response->status);
        self::assertSame('text/plain', $response->header('content-type'));
        self::assertSame(['a', 'b'], $response->headers->all('x-multi'));
        self::assertSame(200, (new Response())->status);
        self::assertSame('', (new Response())->body);
    }

    public function testResponseNamedConstructors(): void
    {
        $json = Response::json(['title' => 'Καλημέρα', 'url' => 'a/b'], 404, ['cache-control' => 'no-store']);
        self::assertSame('{"title":"Καλημέρα","url":"a/b"}', $json->body);
        self::assertSame(404, $json->status);
        self::assertSame('application/json; charset=utf-8', $json->header('content-type'));
        self::assertSame('no-store', $json->header('cache-control'));

        self::assertSame('text/html; charset=utf-8', Response::html('<p>')->header('content-type'));
        self::assertSame('text/plain; charset=utf-8', Response::text('x')->header('content-type'));
    }

    public function testResponseFileReadsAndTypesAFile(): void
    {
        $response = Response::file(__FILE__);

        self::assertSame(file_get_contents(__FILE__), $response->body);
        self::assertSame('application/octet-stream', $response->header('content-type'));
        self::assertSame('text/x-php', Response::file(__FILE__, 'text/x-php')->header('content-type'));

        $this->expectException(\RuntimeException::class);
        Response::file(__DIR__ . '/does-not-exist.css');
    }

    public function testResponseWithersAreImmutable(): void
    {
        $a = new Response('a', 200, ['x' => '1']);
        $b = $a->withStatus(500)->withBody('b')->withHeader('y', '2')->withAddedHeader('x', '3');

        self::assertSame(['a', 200, ['x' => ['1']]], [$a->body, $a->status, $a->headers->toArray()]);
        self::assertSame(['b', 500, ['x' => ['1', '3'], 'y' => ['2']]], [$b->body, $b->status, $b->headers->toArray()]);
    }

    public function testResponseStatusMustBeInRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Response('', 99);
    }
}
