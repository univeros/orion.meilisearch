<?php

declare(strict_types=1);

namespace Orion\Meilisearch\Tests;

use Meilisearch\Client;
use Orion\Adapter\RequiresReindex;
use Orion\IndexName;
use Orion\Meilisearch\MeilisearchEngine;
use Orion\Query\Filter;
use Orion\Query\Query;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Write\Document;
use Orion\Write\WriteStatus;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function getenv;
use function implode;
use function random_bytes;
use function range;
use function str_repeat;

/**
 * What a schemaless, asynchronous engine needs of the lifecycle, checked live: the declaration reads back only where
 * the settings still serve it, a vector cannot join an index in place, and a semantic search's facets count the
 * neighbours.
 */
abstract class MeilisearchSchema extends TestCase
{
    private MeilisearchEngine $engine;
    private IndexName $index;

    abstract protected static function urlVariable(): string;

    protected function setUp(): void
    {
        $this->engine = MeilisearchServer::engine(static::urlVariable(), $this);
        $this->index = IndexName::of('orion_schema_' . bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        if (isset($this->engine)) {
            $this->engine->drop($this->index);
        }
    }

    public function testAVectorCannotJoinAnIndexInPlace(): void
    {
        $this->engine->ensure(IndexSchema::for($this->index->value)->keyword('sku'));
        $plan = $this->engine->plan(IndexSchema::for($this->index->value)->keyword('sku')->vector('embedding', 3));
        self::assertFalse($plan->isEmpty());

        $this->expectException(RequiresReindex::class);
        $this->expectExceptionMessage('embedding');

        $this->engine->apply($plan);
    }

    public function testASettingSomeoneElseChangedReadsBackAsUnknownAndFailsThePlanClosed(): void
    {
        $schema = IndexSchema::for($this->index->value)->keyword('sku', filterable: true, sortable: true)->keyword('brand', filterable: true);
        $this->engine->ensure($schema);
        $client = new Client((string) getenv(static::urlVariable()));
        $client->waitForTask($client->index($this->index->value)->updateSortableAttributes([])['taskUid'], 30000);

        $live = $this->engine->inspect($this->index);

        self::assertNotNull($live);
        self::assertSame(FieldType::Unknown, $live->fields[0]->type, 'sku is declared sortable; the settings no longer sort it.');
        self::assertSame(FieldType::Keyword, $live->fields[1]->type);
        self::assertTrue($this->engine->plan($schema)->requiresReindex());
    }

    public function testALongTextKeepsEveryTokenFindable(): void
    {
        $this->engine->ensure(IndexSchema::for($this->index->value)->text('body'));
        $words = array_map(static fn(int $i): string => 'word' . $i, range(1, 300));
        $this->engine->index($this->index, new Document('long', ['body' => implode(' ', $words)], 1))->await(30);
        $this->engine->flush($this->index, await: true);

        self::assertSame(['long'], array_map(static fn($hit): string => $hit->id, $this->engine->search($this->index, Query::on($this->index->value)->match('body', 'word300'))->hits));
        self::assertSame(['long'], array_map(static fn($hit): string => $hit->id, $this->engine->search($this->index, Query::on($this->index->value)->phrase('body', 'word149 word150 word151'))->hits));
        self::assertSame([], $this->engine->search($this->index, Query::on($this->index->value)->match('body', 'word301'))->hits);
    }

    public function testAWriteStillQueuedCountsForTheVersionOfTheNext(): void
    {
        $this->engine->ensure(IndexSchema::for($this->index->value)->text('title'));
        $newer = $this->engine->index($this->index, new Document('1', ['title' => 'five'], 5));
        $older = $this->engine->index($this->index, new Document('1', ['title' => 'three'], 3))->await(30);
        $newer->await(30);
        $this->engine->flush($this->index, await: true);

        self::assertSame(WriteStatus::Stale, $older->outcomes[0]->status);
        self::assertSame('five', $this->engine->search($this->index, Query::on($this->index->value))->hits[0]->source['title']);
    }

    public function testAnotherProcessesSchemaChangeIsSeenWithoutARestart(): void
    {
        $schema = IndexSchema::for($this->index->value)->text('title');
        $this->engine->ensure($schema);
        $this->engine->index($this->index, new Document('1', ['title' => 'warm'], 1))->await(30);
        $other = MeilisearchServer::engine(static::urlVariable(), $this);
        $other->apply($other->plan($schema->keyword('brand', filterable: true)))->await(30);

        $result = $this->engine->index($this->index, new Document('2', ['title' => 'new', 'brand' => 'acme'], 1))->await(30);

        self::assertSame([], $result->failures());
    }

    public function testAnIdTheEngineCannotHoldFailsAlone(): void
    {
        $this->engine->ensure(IndexSchema::for($this->index->value)->text('title'));

        $result = $this->engine->index($this->index, new Document('ok', ['title' => 'a'], 1), new Document(str_repeat('i', 400), ['title' => 'b'], 1))->await(30);

        self::assertSame([str_repeat('i', 400)], array_map(static fn($outcome): string => $outcome->id, $result->failures()));
    }

    public function testFlushWaitsForEveryQueuedWrite(): void
    {
        $this->engine->ensure(IndexSchema::for($this->index->value)->text('title'));
        for ($i = 1; $i <= 25; ++$i) {
            $this->engine->index($this->index, new Document((string) $i, ['title' => 'doc'], 1));
        }
        $this->engine->flush($this->index, await: true);

        self::assertSame(25, $this->engine->search($this->index, Query::on($this->index->value)->paginate(1, 100))->total);
    }

    public function testAVectorPagePastKIsEmptyAndTotalsK(): void
    {
        $this->engine->ensure(IndexSchema::for($this->index->value)->vector('embedding', 2));
        $this->engine->index($this->index, new Document('a', ['embedding' => [1.0, 0.0]], 1), new Document('b', ['embedding' => [0.9, 0.1]], 1), new Document('c', ['embedding' => [0.0, 1.0]], 1))->await(30);
        $this->engine->flush($this->index, await: true);

        $page = $this->engine->search($this->index, Query::on($this->index->value)->vector('embedding', [1.0, 0.0], k: 2)->paginate(3, 2));

        self::assertSame([], $page->hits);
        self::assertSame(2, $page->total);
    }

    public function testASemanticSearchsFacetsCountTheNeighbours(): void
    {
        $schema = IndexSchema::for($this->index->value)->keyword('brand', filterable: true, facetable: true)->vector('embedding', 2);
        $this->engine->ensure($schema);
        $this->engine->index(
            $this->index,
            new Document('a', ['brand' => 'acme', 'embedding' => [1.0, 0.0]], 1),
            new Document('b', ['brand' => 'acme', 'embedding' => [0.9, 0.1]], 1),
            new Document('c', ['brand' => 'globex', 'embedding' => [0.0, 1.0]], 1),
            new Document('d', ['brand' => 'globex', 'embedding' => [0.1, 0.9]], 1),
        )->await(30);
        $this->engine->flush($this->index, await: true);

        $nearest = $this->engine->search($this->index, Query::on($this->index->value)->vector('embedding', [1.0, 0.0], k: 2)->facet('brand'));
        $filtered = $this->engine->search($this->index, Query::on($this->index->value)->vector('embedding', [1.0, 0.0], k: 3)->filter(Filter::term('brand', 'globex'))->facet('brand'));

        self::assertSame(['acme' => 2], $nearest->facets['brand']);
        self::assertSame(2, $nearest->total);
        self::assertSame(['globex' => 2], $filtered->facets['brand']);
        self::assertSame(2, $filtered->total);
    }

    public function testFacetKeysAgreeWhetherCountedByTheEngineOrOverTheNeighbours(): void
    {
        $schema = IndexSchema::for($this->index->value)->date('released', filterable: true, facetable: true)->float('price', filterable: true, facetable: true)->vector('embedding', 2);
        $this->engine->ensure($schema);
        $this->engine->index($this->index, new Document('a', ['released' => '2026-01-10', 'price' => 120.0, 'embedding' => [1.0, 0.0]], 1), new Document('b', ['released' => '2026-01-10', 'price' => 7.5, 'embedding' => [0.9, 0.1]], 1))->await(30);
        $this->engine->flush($this->index, await: true);

        $lexical = $this->engine->search($this->index, Query::on($this->index->value)->facet('released')->facet('price'));
        $semantic = $this->engine->search($this->index, Query::on($this->index->value)->vector('embedding', [1.0, 0.0], k: 2)->facet('released')->facet('price'));

        self::assertSame($lexical->facets, $semantic->facets);
        self::assertSame(['2026-01-10T00:00:00Z' => 2], $lexical->facets['released']);
        self::assertSame(['7.5' => 1, '120' => 1], $lexical->facets['price'], 'ties by key, numerically as PHP compares numeric strings');
    }
}
