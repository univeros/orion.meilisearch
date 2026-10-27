<?php

declare(strict_types=1);

namespace Orion\Meilisearch\Tests;

use Orion\Meilisearch\Documents;
use Orion\Schema\Field;
use Orion\Schema\IndexSchema;
use Orion\Write\Document;
use Orion\Write\Tombstone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_slice;
use function bin2hex;
use function count;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function implode;
use function range;
use function str_contains;
use function str_repeat;
use function strlen;

/**
 * An Orion document as a Meilisearch document: the source as written, Orion's own attributes, and one shadow
 * attribute per declared field that the compiler filters, sorts and facets on.
 */
final class DocumentsTest extends TestCase
{
    public function testIdsAreEncodedToWhatTheEngineAcceptsAndBack(): void
    {
        self::assertSame('dYWNtZTpwMQ', Documents::id('acme:p1'));
        self::assertSame('acme:p1', Documents::orionId('dYWNtZTpwMQ'));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', Documents::id('weird id/with+slashes=and&more'));
        self::assertSame('weird id/with+slashes=and&more', Documents::orionId(Documents::id('weird id/with+slashes=and&more')));
    }

    public function testADocumentCarriesItsSourceItsShadowsAndItsVectors(): void
    {
        $encoded = Documents::encode(new Document('1', [
            'title' => 'Trail Running shoe',
            'sku' => 'A1',
            'tags' => ['New', 'sale'],
            'price' => 120.0,
            'stock' => '5',
            'in_stock' => true,
            'released' => '2026-01-10',
            'at' => ['lat' => 38.7, 'lon' => -9.1],
            'embedding' => [1.0, 0.0, 0.0],
            'address' => ['city' => 'Lisbon'],
        ], 7), self::schema());

        self::assertSame('dMQ', $encoded['id']);
        self::assertSame('1', $encoded['orion_id']);
        self::assertSame(7, $encoded['orion_version']);
        self::assertFalse($encoded['orion_tombstone']);
        self::assertSame('Trail Running shoe', $encoded['orion_src']['title']);
        self::assertSame(['city' => 'Lisbon'], $encoded['orion_src']['address']);
        self::assertArrayNotHasKey('embedding', $encoded['orion_src']);
        self::assertArrayNotHasKey('title', $encoded);
        self::assertSame('x747261696cx72756e6e696e67x73686f65x', $encoded['orion_f_title']);
        self::assertSame('4131', $encoded['orion_f_sku']);
        self::assertSame(['4e6577', '73616c65'], $encoded['orion_f_tags']);
        self::assertSame(120.0, $encoded['orion_f_price']);
        self::assertSame(5, $encoded['orion_f_stock']);
        self::assertTrue($encoded['orion_f_in_stock']);
        self::assertSame(1768003200, $encoded['orion_f_released']);
        self::assertSame(['lat' => 38.7, 'lng' => -9.1], $encoded['_geo']);
        self::assertSame('4c6973626f6e', $encoded['orion_f_address__city']);
        self::assertSame(['orion_f_embedding' => [1.0, 0.0, 0.0]], $encoded['_vectors']);
    }

    /**
     * The engine truncates a filterable string at 500 bytes, so a long text's tokens are spread over several strings
     * that overlap by more tokens than a phrase has, and the field's shadow is the list of them.
     */
    public function testALongTextIsChunkedSoEveryTokenStaysFindable(): void
    {
        $words = array_map(static fn(int $i): string => 'word' . $i, range(1, 300));
        $chunks = Documents::tokens(implode(' ', $words));

        self::assertIsArray($chunks);
        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(Documents::CHUNK_BYTES, strlen($chunk));
            self::assertStringStartsWith('x', $chunk);
            self::assertStringEndsWith('x', $chunk);
        }
        self::assertStringContainsString('x' . bin2hex('word300') . 'x', $chunks[count($chunks) - 1]);
        self::assertStringContainsString('x' . bin2hex('word1') . 'x', $chunks[0]);
        // Any phrase of up to the overlap sits whole in one chunk.
        $phrase = implode('x', array_map(bin2hex(...), array_slice($words, 100, Documents::CHUNK_OVERLAP)));
        self::assertNotSame([], array_filter($chunks, static fn(string $chunk): bool => str_contains($chunk, 'x' . $phrase . 'x')));
        self::assertSame('x' . bin2hex('short') . 'x', Documents::tokens('short'));
    }

    public function testAKeywordLongerThanTheEngineKeepsIsRefused(): void
    {
        self::assertStringContainsString('sku', (string) Documents::invalid(['sku' => str_repeat('k', 201)], self::schema()->fields));
        self::assertNull(Documents::invalid(['sku' => str_repeat('k', 200)], self::schema()->fields));
    }

    public function testAnIdTheEngineCannotHoldIsRefused(): void
    {
        self::assertNull(Documents::invalidId(str_repeat('i', 380)));
        self::assertStringContainsString('id', (string) Documents::invalidId(str_repeat('i', 390)));
    }

    public function testADateWithoutAZoneIsReadAsUtcWhateverTheProcessDefault(): void
    {
        $default = date_default_timezone_get();
        date_default_timezone_set('Europe/Madrid');
        try {
            self::assertSame(1770681600, Documents::instant('2026-02-10'));
            self::assertSame(1770681600 + 3600, Documents::instant('2026-02-10T01:00:00Z'));
            self::assertSame(1770681600, Documents::instant('2026-02-10T01:00:00+01:00'));
        } finally {
            date_default_timezone_set($default);
        }
    }

    public function testADocumentWithoutAVectorOptsOutOfTheEmbedder(): void
    {
        $encoded = Documents::encode(new Document('1', ['title' => 'x'], 1), self::schema());

        self::assertSame(['orion_f_embedding' => null], $encoded['_vectors']);
        self::assertArrayNotHasKey('orion_f_sku', $encoded);
    }

    public function testATombstoneReplacesTheDocumentAndOptsOutOfEveryEmbedder(): void
    {
        $tombstone = Documents::tombstone(new Tombstone('acme:p1', 9), self::schema());

        self::assertSame(['id' => 'dYWNtZTpwMQ', 'orion_id' => 'acme:p1', 'orion_version' => 9, 'orion_tombstone' => true, '_vectors' => ['orion_f_embedding' => null]], $tombstone);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'an undeclared field' => [['title' => 'x', 'color' => 'red'], 'color'];
        yield 'text in an integer' => [['stock' => 'many'], 'stock'];
        yield 'a short vector' => [['embedding' => [1.0, 0.0]], 'embedding'];
        yield 'a date that is not one' => [['released' => 'yesterday'], 'released'];
        yield 'an undeclared field inside an object' => [['address' => ['street' => 'x']], 'address.street'];
        yield 'an object where a scalar belongs' => [['sku' => ['a' => 1]], 'sku'];
        yield 'a geo point without coordinates' => [['at' => 'Lisbon'], 'at'];
    }

    /**
     * @param array<string, mixed> $source
     */
    #[DataProvider('invalid')]
    public function testAnInvalidDocumentIsRefusedBeforeItReachesTheEngine(array $source, string $field): void
    {
        self::assertStringContainsString($field, (string) Documents::invalid($source, self::schema()->fields));
        self::assertNull(Documents::invalid(['title' => 'x', 'stock' => 3, 'address' => ['city' => 'y']], self::schema()->fields));
    }

    public function testAHitReadsBackAsTheSourceWithoutOrionsAttributes(): void
    {
        $hit = ['id' => 'd1', 'orion_id' => '1', 'orion_version' => 7, 'orion_tombstone' => false, 'orion_src' => ['title' => 'x', 'address' => ['city' => 'Lisbon']], '_rankingScore' => 0.5];

        self::assertSame(['title' => 'x', 'address' => ['city' => 'Lisbon']], Documents::source($hit));
    }

    public function testFacetKeysDecodeToTheValues(): void
    {
        self::assertSame(['acme' => 2, 'globex' => 1], Documents::facet(['61636d65' => 2, '676c6f626578' => 1], Field::keyword('brand')));
        self::assertSame(['120' => 1, '7.5' => 2], Documents::facet(['120.0' => 1, '7.5' => 2], Field::float('price')));
        self::assertSame(['2026-01-10T00:00:00Z' => 1], Documents::facetKeys(['2026-01-10' => 1], Field::date('released')), 'a source value and an engine key agree');
        self::assertSame(['true' => 2], Documents::facet(['true' => 2], Field::boolean('in_stock')));
        self::assertSame(['2026-01-10T00:00:00Z' => 1], Documents::facet(['1768003200' => 1], Field::date('released')));
        self::assertSame(['5' => 1, '7.5' => 2], Documents::facet(['5' => 1, '7.5' => 2], Field::float('price')));
    }

    private static function schema(): IndexSchema
    {
        return IndexSchema::for('products')
            ->text('title', language: 'en')
            ->keyword('sku', filterable: true, sortable: true)
            ->keyword('tags', filterable: true)
            ->float('price', filterable: true, sortable: true)
            ->integer('stock', filterable: true, sortable: true)
            ->boolean('in_stock', filterable: true)
            ->date('released', filterable: true, sortable: true)
            ->geo('at', filterable: true)
            ->vector('embedding', dims: 3)
            ->object('address', Field::keyword('city', filterable: true));
    }
}
