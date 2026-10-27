<?php

declare(strict_types=1);

namespace Orion\Meilisearch;

use InvalidArgumentException;
use Orion\Adapter\Capability;
use Orion\Adapter\UnsupportedClause;
use Orion\Query\Facet;
use Orion\Query\Page;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Schema\VectorMetric;
use Orion\Support\Assert;
use stdClass;

use function array_map;
use function implode;
use function in_array;
use function is_array;
use function sprintf;

/**
 * The neutral schema as index settings, with Orion's declaration kept in a tombstone document beside them
 * ({@see DECLARATION_ID}): Meilisearch is schemaless, so the declaration is what `inspect()` reports, and only where
 * the settings still agree (a shadow attribute filterable, sortable where declared, an embedder of the declared
 * dimensions); a field the settings no longer serve reads back as {@see FieldType::Unknown}, so a plan touching it
 * fails closed. Every shadow is filterable, because Orion's lexical clauses are filters on them; `filterable` is a
 * declaration the binder enforces, not an engine setting.
 */
final class Settings
{
    public const string ENGINE = 'meilisearch';
    public const string DECLARATION_ID = 'orion-schema';
    public const string DECLARATION = 'orion_schema';
    /** What Orion sets for its own queries, the tenant scope and the hit envelope: native settings cannot override it. */
    private const array OWNED = ['filterableAttributes', 'sortableAttributes', 'displayedAttributes', 'embedders', 'pagination', 'faceting', 'distinctAttribute'];
    /** The settings a host may set: they shape `q` and relevance for the escape hatch and never reach past the index. */
    private const array ALLOWED = [
        'searchableAttributes', 'rankingRules', 'synonyms', 'stopWords', 'typoTolerance', 'dictionary', 'separatorTokens',
        'nonSeparatorTokens', 'proximityPrecision', 'localizedAttributes', 'searchCutoffMs', 'facetSearch', 'prefixSearch',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function create(IndexSchema $schema): array
    {
        $filterable = [Documents::ID, Documents::TOMBSTONE];
        $sortable = [];
        $embedders = [];
        $geo = 0;
        $shadows = [];
        foreach (Documents::flatten($schema->fields) as $path => $field) {
            $shadow = Documents::shadow($path);
            if (isset($shadows[$shadow])) {
                throw new InvalidArgumentException(sprintf('The fields %s and %s share the shadow attribute %s: one path with dots and one with double underscores.', Assert::show($shadows[$shadow]), Assert::show($path), Assert::show($shadow)));
            }
            $shadows[$shadow] = $path;
            if ($field->type === FieldType::Nested) {
                throw UnsupportedClause::missing(Capability::NestedDocuments, 'nested field ' . Assert::show($path), self::ENGINE);
            }
            if ($field->type === FieldType::Object) {
                continue;
            }
            if ($field->type === FieldType::Vector) {
                if (($field->metric ?? VectorMetric::Cosine) !== VectorMetric::Cosine) {
                    throw UnsupportedClause::missing(Capability::Vectors, sprintf('the %s metric of %s (Meilisearch ranks by cosine similarity)', ($field->metric ?? VectorMetric::Cosine)->value, Assert::show($path)), self::ENGINE);
                }
                $embedders[Documents::shadow($path)] = ['source' => 'userProvided', 'dimensions' => $field->dims];
                continue;
            }
            if ($field->type === FieldType::Geo) {
                if (++$geo > 1) {
                    throw new InvalidArgumentException(sprintf('Meilisearch holds one geo point per document (_geo); %s is a second geo field.', Assert::show($path)));
                }
                $filterable[] = Documents::GEO;
                if ($field->sortable) {
                    $sortable[] = Documents::GEO;
                }
                continue;
            }
            $filterable[] = Documents::shadow($path);
            if ($field->sortable) {
                $sortable[] = Documents::shadow($path);
            }
        }
        $settings = [
            'searchableAttributes' => [Documents::SOURCE],
            'filterableAttributes' => $filterable,
            'sortableAttributes' => $sortable,
            'displayedAttributes' => [Documents::ORION_ID, Documents::VERSION, Documents::TOMBSTONE, Documents::SOURCE],
            'embedders' => $embedders === [] ? new stdClass() : $embedders,
            'pagination' => ['maxTotalHits' => Page::WINDOW],
            'faceting' => ['maxValuesPerFacet' => Facet::MAX_SIZE, 'sortFacetValuesBy' => ['*' => 'count']],
        ];

        return [...$settings, ...self::native($schema)];
    }

    /**
     * The declaration document: a tombstone (never a hit) carrying the declared fields.
     *
     * @return array<string, mixed>
     */
    public static function declaration(IndexSchema $schema): array
    {
        $vectors = [];
        foreach (Documents::flatten($schema->fields) as $path => $field) {
            if ($field->type === FieldType::Vector) {
                $vectors[Documents::shadow($path)] = null;
            }
        }

        return [
            Documents::ID => self::DECLARATION_ID,
            Documents::TOMBSTONE => true,
            self::DECLARATION => ['fields' => Field::mapToArray($schema->fields)],
            ...($vectors === [] ? [] : [Documents::VECTORS => $vectors]),
        ];
    }

    /**
     * The declared fields as declared, for compiling queries and encoding documents.
     *
     * @param array<string, mixed> $declaration the declaration document
     * @return list<Field>
     */
    public static function declared(array $declaration): array
    {
        $map = is_array($declaration[self::DECLARATION] ?? null) ? ($declaration[self::DECLARATION]['fields'] ?? []) : [];

        return !is_array($map) || $map === [] ? [] : Field::mapFromArray($map, self::DECLARATION . '.fields');
    }

    /**
     * The declared fields, each kept only where the settings still serve it.
     *
     * @param array<string, mixed> $declaration the declaration document
     * @param array<string, mixed> $settings the index's settings
     * @return list<Field>
     */
    public static function fields(array $declaration, array $settings): array
    {
        return array_map(static fn(Field $field): Field => self::verify($field, $field->name, $settings), self::declared($declaration));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function verify(Field $field, string $path, array $settings): Field
    {
        $filterable = (array) ($settings['filterableAttributes'] ?? []);
        $sortable = (array) ($settings['sortableAttributes'] ?? []);
        $embedders = (array) ($settings['embedders'] ?? []);
        $attribute = $field->type === FieldType::Geo ? Documents::GEO : Documents::shadow($path);

        return match ($field->type) {
            FieldType::Object => Field::object($field->name, ...array_map(static fn(Field $child): Field => self::verify($child, $path . '.' . $child->name, $settings), $field->fields)),
            FieldType::Nested, FieldType::Unknown => Field::unknown($field->name),
            FieldType::Vector => self::embedderServes(is_array($embedders[$attribute] ?? null) ? $embedders[$attribute] : [], $field) ? $field : Field::unknown($field->name),
            default => in_array($attribute, $filterable, true) && (!$field->sortable || in_array($attribute, $sortable, true)) ? $field : Field::unknown($field->name),
        };
    }

    /**
     * @param array<array-key, mixed> $embedder
     */
    private static function embedderServes(array $embedder, Field $field): bool
    {
        return ($embedder['source'] ?? null) === 'userProvided' && ($embedder['dimensions'] ?? null) === $field->dims;
    }

    /**
     * `IndexSchema::native('meilisearch', ['settings' => [...]])`: index settings merged over the generated ones,
     * except what Orion owns.
     *
     * @return array<string, mixed>
     */
    private static function native(IndexSchema $schema): array
    {
        $native = $schema->native[self::ENGINE] ?? [];
        foreach ($native as $key => $_) {
            if ($key !== 'settings') {
                throw new InvalidArgumentException(sprintf('Native Meilisearch settings take "settings" only, not %s.', Assert::show((string) $key)));
            }
        }
        $settings = is_array($native['settings'] ?? null) ? $native['settings'] : [];
        foreach ($settings as $key => $_) {
            if (in_array($key, self::OWNED, true)) {
                throw new InvalidArgumentException(sprintf('Native Meilisearch settings cannot set %s: Orion\'s queries, the tenant scope and the hit envelope depend on it (Orion owns %s).', Assert::show((string) $key), implode(', ', self::OWNED)));
            }
            if (!in_array($key, self::ALLOWED, true)) {
                throw new InvalidArgumentException(sprintf('Native Meilisearch settings cannot set %s; a host may set %s.', Assert::show((string) $key), implode(', ', self::ALLOWED)));
            }
        }

        /** @var array<string, mixed> */
        return $settings;
    }
}
