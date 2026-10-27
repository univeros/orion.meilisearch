<?php

declare(strict_types=1);

namespace Orion\Meilisearch\Tests;

use InvalidArgumentException;
use Orion\Adapter\UnsupportedClause;
use Orion\IndexName;
use Orion\Meilisearch\Settings;
use Orion\Query\Facet;
use Orion\Query\Page;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;
use Orion\Schema\VectorMetric;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The neutral schema as index settings plus Orion's declaration document, and back.
 */
final class SettingsTest extends TestCase
{
    public function testEveryNeutralTypeRoundTripsWithoutDrift(): void
    {
        $schema = self::schema();

        $live = new LiveSchema(IndexName::of('products'), Settings::fields(Settings::declaration($schema), Settings::create($schema)));

        self::assertTrue(MigrationPlan::between($schema, $live)->isEmpty());
    }

    public function testTheSettingsCarryTheShadowsAndOrionsOwnAttributes(): void
    {
        $settings = Settings::create(self::schema());

        self::assertSame(['orion_src'], $settings['searchableAttributes']);
        self::assertSame(
            ['id', 'orion_tombstone', 'orion_f_title', 'orion_f_description', 'orion_f_sku', 'orion_f_brand', 'orion_f_note', 'orion_f_stock', 'orion_f_price', 'orion_f_in_stock', 'orion_f_released', '_geo', 'orion_f_address__city'],
            $settings['filterableAttributes'],
        );
        self::assertSame(['orion_f_sku', 'orion_f_price', 'orion_f_released', '_geo'], $settings['sortableAttributes']);
        self::assertSame(['orion_id', 'orion_version', 'orion_tombstone', 'orion_src'], $settings['displayedAttributes']);
        self::assertSame(['orion_f_embedding' => ['source' => 'userProvided', 'dimensions' => 3]], $settings['embedders']);
        self::assertSame(['maxTotalHits' => Page::WINDOW], $settings['pagination']);
        self::assertSame(['maxValuesPerFacet' => Facet::MAX_SIZE, 'sortFacetValuesBy' => ['*' => 'count']], $settings['faceting']);
    }

    public function testTheDeclarationIsATombstoneDocumentWithTheFields(): void
    {
        $declaration = Settings::declaration(self::schema());

        self::assertSame(Settings::DECLARATION_ID, $declaration['id']);
        self::assertTrue($declaration['orion_tombstone']);
        self::assertSame(Field::mapToArray(self::schema()->fields), $declaration['orion_schema']['fields']);
        self::assertSame(['orion_f_embedding' => null], $declaration['_vectors']);
    }

    public function testAVectorWhoseEmbedderIsGoneOrResizedIsUnknown(): void
    {
        $schema = self::schema();
        $settings = Settings::create($schema);
        $resized = [...$settings, 'embedders' => ['orion_f_embedding' => ['source' => 'userProvided', 'dimensions' => 4]]];
        $gone = [...$settings, 'embedders' => []];

        self::assertSame(FieldType::Unknown, self::field(Settings::fields(Settings::declaration($schema), $resized), 'embedding')->type);
        self::assertSame(FieldType::Unknown, self::field(Settings::fields(Settings::declaration($schema), $gone), 'embedding')->type);
    }

    public function testAFieldWhoseShadowIsNoLongerFilterableOrSortableIsUnknown(): void
    {
        $schema = self::schema();
        $settings = Settings::create($schema);
        $unfilterable = [...$settings, 'filterableAttributes' => array_values(array_diff($settings['filterableAttributes'], ['orion_f_brand']))];
        $unsortable = [...$settings, 'sortableAttributes' => array_values(array_diff($settings['sortableAttributes'], ['orion_f_price']))];

        self::assertSame(FieldType::Unknown, self::field(Settings::fields(Settings::declaration($schema), $unfilterable), 'brand')->type);
        self::assertSame(FieldType::Unknown, self::field(Settings::fields(Settings::declaration($schema), $unsortable), 'price')->type);
        self::assertSame(FieldType::Keyword, self::field(Settings::fields(Settings::declaration($schema), $unsortable), 'brand')->type);
    }

    public function testADeclarationWithoutFieldsReadsAsNoFields(): void
    {
        self::assertSame([], Settings::fields(['id' => Settings::DECLARATION_ID], Settings::create(IndexSchema::for('products'))));
    }

    public function testNativeSettingsMergeOverTheGeneratedOnes(): void
    {
        $settings = Settings::create(IndexSchema::for('products')->text('title')->native('meilisearch', ['settings' => ['typoTolerance' => ['enabled' => false], 'synonyms' => ['shoe' => ['sneaker']]]]));

        self::assertSame(['enabled' => false], $settings['typoTolerance']);
        self::assertSame(['shoe' => ['sneaker']], $settings['synonyms']);
        self::assertSame(['orion_src'], $settings['searchableAttributes']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function orionsOwn(): iterable
    {
        yield 'filterable attributes' => [['filterableAttributes' => ['title']]];
        yield 'sortable attributes' => [['sortableAttributes' => []]];
        yield 'displayed attributes' => [['displayedAttributes' => ['*']]];
        yield 'embedders' => [['embedders' => []]];
        yield 'pagination' => [['pagination' => ['maxTotalHits' => 50]]];
        yield 'faceting' => [['faceting' => ['maxValuesPerFacet' => 1]]];
        yield 'a distinct attribute' => [['distinctAttribute' => 'brand']];
        yield 'a setting Orion does not know' => [['foreignKeys' => []]];
        yield 'the chat settings' => [['chat' => ['description' => 'x']]];
    }

    /**
     * What Orion sets for its own queries, the tenant scope and the hit envelope cannot be overridden.
     *
     * @param array<string, mixed> $settings
     */
    #[DataProvider('orionsOwn')]
    public function testNativeSettingsCannotTouchWhatOrionOwns(array $settings): void
    {
        $this->expectException(InvalidArgumentException::class);

        Settings::create(IndexSchema::for('products')->text('title')->native('meilisearch', ['settings' => $settings]));
    }

    public function testNativeSettingsAreSettingsOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Settings::create(IndexSchema::for('products')->text('title')->native('meilisearch', ['primaryKey' => 'x']));
    }

    public function testTwoFieldsCannotShareAShadowAttribute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('orion_f_a__b');

        Settings::create(IndexSchema::for('products')->object('a', Field::keyword('b'))->keyword('a__b'));
    }

    public function testNestedDocumentsAreNotSupported(): void
    {
        $this->expectException(UnsupportedClause::class);

        Settings::create(IndexSchema::for('products')->nested('variants', Field::keyword('size')));
    }

    public function testOnlyOneGeoFieldFitsTheEnginesGeoPoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('_geo');

        Settings::create(IndexSchema::for('products')->geo('at')->geo('from'));
    }

    public function testOnlyCosineSimilarityIsAvailable(): void
    {
        $this->expectException(UnsupportedClause::class);

        Settings::create(IndexSchema::for('products')->vector('embedding', 3, VectorMetric::Euclidean));
    }

    private static function schema(): IndexSchema
    {
        return IndexSchema::for('products')
            ->text('title', language: 'en', boost: 3.0)
            ->text('description', folding: true)
            ->keyword('sku', filterable: true, sortable: true)
            ->keyword('brand', facetable: true)
            ->keyword('note')
            ->integer('stock', filterable: true)
            ->float('price', filterable: true, sortable: true, facetable: true)
            ->boolean('in_stock', filterable: true, facetable: true)
            ->date('released', sortable: true)
            ->geo('at', filterable: true, sortable: true)
            ->vector('embedding', dims: 3, metric: VectorMetric::Cosine)
            ->object('address', Field::keyword('city', filterable: true));
    }

    /**
     * @param list<Field> $fields
     */
    private static function field(array $fields, string $name): Field
    {
        return Field::byName($fields)[$name];
    }
}
