<?php

declare(strict_types=1);

namespace Orion\Meilisearch\Tests;

use InvalidArgumentException;
use Orion\Adapter\UnsupportedClause;
use Orion\Meilisearch\QueryCompiler;
use Orion\Query\Direction;
use Orion\Query\Filter;
use Orion\Query\Fusion;
use Orion\Query\Query;
use Orion\Query\Sort;
use Orion\Schema\IndexSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;

/**
 * The neutral query as a Meilisearch search body. Lexical clauses are filters on Orion's shadow attributes (hex-encoded
 * so the engine's case-insensitive filters stay exact; a text field's tokens as one delimited string), never `q`.
 */
final class QueryCompilerTest extends TestCase
{
    private const string RUNNING = '72756e6e696e67';
    private const string SHOE = '73686f65';
    private const string ACME = '61636d65';

    public function testALexicalQueryCompilesToFiltersOnTheShadowAttributes(): void
    {
        $query = Query::on('products')
            ->match('title', 'running shoe')
            ->filter(Filter::range('price', lte: 100))
            ->filter(Filter::bool(must: [Filter::term('in_stock', true)], should: [Filter::term('brand', 'acme')]))
            ->facet('brand', size: 5)
            ->sort(Sort::SCORE, Direction::Desc)
            ->sort('price', Direction::Desc)
            ->highlight('title')
            ->paginate(page: 2, size: 20);

        self::assertSame([
            'filter' => [
                'orion_tombstone = false',
                '(orion_f_title CONTAINS "x' . self::RUNNING . 'x" OR orion_f_title CONTAINS "x' . self::SHOE . 'x")',
                'orion_f_price <= 100',
                '(orion_f_in_stock = true AND (orion_f_brand = "' . self::ACME . '"))',
            ],
            'sort' => ['orion_f_price:desc'],
            'facets' => ['orion_f_brand'],
            'page' => 2,
            'hitsPerPage' => 20,
            'showRankingScore' => true,
        ], $this->compile($query));
    }

    public function testAnEmptyQueryMatchesEverythingButTombstones(): void
    {
        self::assertSame(['orion_tombstone = false'], $this->compile(Query::on('products'))['filter']);
    }

    /**
     * @return iterable<string, array{Query, string}>
     */
    public static function clauses(): iterable
    {
        yield 'term on a keyword' => [Query::on('products')->term('sku', 'C3'), 'orion_f_sku = "4333"'];
        yield 'term on a keyword does not fold case' => [Query::on('products')->term('brand', 'ACME'), 'orion_f_brand = "41434d45"'];
        yield 'term on text is one token' => [Query::on('products')->term('title', 'shoe'), 'orion_f_title CONTAINS "x' . self::SHOE . 'x"'];
        yield 'prefix on text is the start of a token' => [Query::on('products')->prefix('title', 'hik'), 'orion_f_title CONTAINS "x68696b"'];
        yield 'prefix on a keyword is the start of the value' => [Query::on('products')->prefix('sku', 'A'), 'orion_f_sku STARTS WITH "41"'];
        yield 'phrase is the tokens in sequence' => [Query::on('products')->phrase('title', 'Running Shoe'), 'orion_f_title CONTAINS "x' . self::RUNNING . 'x' . self::SHOE . 'x"'];
        yield 'match on a keyword is the whole value' => [Query::on('products')->match('sku', 'SKU-123'), 'orion_f_sku = "534b552d313233"'];
        yield 'match over several fields is a disjunction' => [Query::on('products')->match(['title', 'sku'], 'E5'), '(orion_f_title CONTAINS "x6535x" OR orion_f_sku = "4535")'];
        yield 'match folds case into the tokens' => [Query::on('products')->match('title', 'RUNNING'), 'orion_f_title CONTAINS "x' . self::RUNNING . 'x"'];
    }

    #[DataProvider('clauses')]
    public function testEachClauseIsAFilterWithTheOracleSemantics(Query $query, string $expected): void
    {
        self::assertSame(['orion_tombstone = false', $expected], $this->compile($query)['filter']);
    }

    /**
     * @return iterable<string, array{Filter, string}>
     */
    public static function filters(): iterable
    {
        yield 'terms' => [Filter::terms('brand', ['acme', 'globex']), 'orion_f_brand IN ["' . self::ACME . '", "676c6f626578"]'];
        yield 'range' => [Filter::range('price', gte: 90, lt: 150), '(orion_f_price >= 90 AND orion_f_price < 150)'];
        yield 'range on a date is on the instant' => [Filter::range('released', gte: '2026-03-01'), 'orion_f_released >= 1772323200'];
        yield 'term on a date' => [Filter::term('released', '2026-02-10'), 'orion_f_released = 1770681600'];
        yield 'term on an integer reads the value as its field' => [Filter::term('stock', 5.0), 'orion_f_stock = 5'];
        yield 'term on a boolean' => [Filter::term('in_stock', false), 'orion_f_in_stock = false'];
        yield 'a fractional value never equals an integer' => [Filter::term('stock', 1.5), '(orion_tombstone = true)'];
        yield 'fractional bounds round towards the integers they admit' => [Filter::range('stock', gt: 1.5, gte: 2.5, lt: 3.5, lte: 4.5), '(orion_f_stock > 1 AND orion_f_stock >= 3 AND orion_f_stock < 4 AND orion_f_stock <= 4)'];
        yield 'exists' => [Filter::exists('stock'), 'orion_f_stock EXISTS'];
        yield 'exists on an object is any of its fields' => [Filter::exists('address'), '(orion_f_address__city EXISTS OR orion_f_address__zip EXISTS)'];
        yield 'bool with must not' => [Filter::bool(must: [Filter::term('in_stock', true)], mustNot: [Filter::term('brand', 'acme')]), '(orion_f_in_stock = true AND NOT (orion_f_brand = "' . self::ACME . '"))'];
        yield 'bool with should only' => [Filter::bool(should: [Filter::term('brand', 'acme'), Filter::term('brand', 'globex')]), '((orion_f_brand = "' . self::ACME . '" OR orion_f_brand = "676c6f626578"))'];
    }

    #[DataProvider('filters')]
    public function testEachFilterIsAFilterExpression(Filter $filter, string $expected): void
    {
        self::assertSame(['orion_tombstone = false', $expected], $this->compile(Query::on('products')->filter($filter))['filter']);
    }

    public function testAPrefixOnANumberIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stock');

        $this->compile(Query::on('products')->prefix('stock', '4'));
    }

    public function testAVectorQueryIsASemanticSearchCutAtK(): void
    {
        $body = $this->compile(Query::on('products')->vector('embedding', [0.1, 0.2, 0.3], k: 5)->filter(Filter::term('brand', 'acme'))->paginate(1, 20));

        self::assertSame(['orion_tombstone = false', 'orion_f_brand = "' . self::ACME . '"'], $body['filter']);
        self::assertSame([0.1, 0.2, 0.3], $body['vector']);
        self::assertSame(['embedder' => 'orion_f_embedding', 'semanticRatio' => 1.0], $body['hybrid']);
        self::assertSame(0, $body['offset']);
        self::assertSame(5, $body['limit']);
        self::assertArrayNotHasKey('page', $body);
    }

    /**
     * @return iterable<string, array{int, int, int, int, int}>
     */
    public static function vectorPages(): iterable
    {
        yield 'a page wider than k' => [5, 1, 20, 0, 5];
        yield 'a page inside k' => [5, 2, 2, 2, 2];
        yield 'a page reaching past k' => [5, 2, 3, 3, 2];
        yield 'a page beyond k starts at k, so the total stays k' => [5, 3, 3, 5, 0];
    }

    #[DataProvider('vectorPages')]
    public function testAVectorQueryNeverPagesPastK(int $k, int $page, int $size, int $offset, int $limit): void
    {
        $body = $this->compile(Query::on('products')->vector('embedding', [0.1, 0.2, 0.3], k: $k)->paginate($page, $size));

        self::assertSame($offset, $body['offset']);
        self::assertSame($limit, $body['limit']);
    }

    public function testHybridRetrievalIsLeftToCore(): void
    {
        $this->expectException(UnsupportedClause::class);

        $this->compile(Query::on('products')->match('title', 'x')->vector('embedding', [0.1, 0.2, 0.3], k: 2)->fuse(Fusion::reciprocalRank()));
    }

    public function testCompilingIsPure(): void
    {
        $query = Query::on('products')->match('title', 'shoe')->filter(Filter::terms('brand', ['a', 'b']))->facet('brand');

        self::assertSame(json_encode($this->compile($query)), json_encode($this->compile($query)));
    }

    public function testAQuotedValueCannotLeaveItsFilter(): void
    {
        $filter = $this->compile(Query::on('products')->filter(Filter::term('brand', 'a" OR orion_tombstone = true OR "')))['filter'][1];

        self::assertSame('orion_f_brand = "6122204f52206f72696f6e5f746f6d6273746f6e65203d2074727565204f522022"', $filter);
    }

    public function testScopeAndsTheFiltersIntoANativeBody(): void
    {
        $scoped = (new QueryCompiler())->scope(['q' => 'shoe', 'filter' => 'orion_f_price > 1', 'limit' => 5], [Filter::term('brand', 'acme')], self::fields());

        self::assertSame(['q' => 'shoe', 'filter' => ['orion_f_price > 1', 'orion_tombstone = false', 'orion_f_brand = "' . self::ACME . '"'], 'limit' => 5], $scoped);
    }

    public function testScopeKeepsAFilterListAsAConjunction(): void
    {
        $scoped = (new QueryCompiler())->scope(['filter' => [['a = 1', 'b = 2'], 'c = 3']], [Filter::term('brand', 'acme')], self::fields());

        self::assertSame([['a = 1', 'b = 2'], 'c = 3', 'orion_tombstone = false', 'orion_f_brand = "' . self::ACME . '"'], $scoped['filter']);
    }

    public function testAnUnscopedNativeBodyStillExcludesTombstones(): void
    {
        self::assertSame(['orion_tombstone = false'], (new QueryCompiler())->scope(['q' => 'x'], [], self::fields())['filter']);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function malformed(): iterable
    {
        yield 'a filter that is neither a string nor a list' => [['filter' => ['a' => 'b']]];
        yield 'a filter object' => [['filter' => (object) ['x' => 1]]];
        yield 'a list body' => [['q', 'x']];
    }

    /**
     * @param array<array-key, mixed> $body
     */
    #[DataProvider('malformed')]
    public function testAMalformedNativeBodyIsRefused(array $body): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QueryCompiler())->scope($body, [Filter::term('brand', 'acme')], self::fields());
    }

    /**
     * @return array<string, mixed>
     */
    private function compile(Query $query): array
    {
        return (new QueryCompiler())->compile($query, self::fields());
    }

    private static function fields(): IndexSchema
    {
        return IndexSchema::for('products')
            ->text('title', language: 'en')
            ->keyword('sku', filterable: true, sortable: true)
            ->keyword('brand', filterable: true, facetable: true)
            ->float('price', filterable: true, sortable: true)
            ->integer('stock', filterable: true, sortable: true)
            ->boolean('in_stock', filterable: true)
            ->date('released', filterable: true, sortable: true)
            ->vector('embedding', dims: 3)
            ->object('address', \Orion\Schema\Field::keyword('city', filterable: true), \Orion\Schema\Field::keyword('zip', filterable: true));
    }
}
