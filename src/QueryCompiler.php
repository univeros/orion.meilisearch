<?php

declare(strict_types=1);

namespace Orion\Meilisearch;

use InvalidArgumentException;
use LogicException;
use Orion\Adapter\Capability;
use Orion\Adapter\InMemory\Matcher;
use Orion\Adapter\UnsupportedClause;
use Orion\Query\BoolFilter;
use Orion\Query\Clause;
use Orion\Query\ClauseKind;
use Orion\Query\ExistsFilter;
use Orion\Query\Facet;
use Orion\Query\Filter;
use Orion\Query\Query;
use Orion\Query\RangeFilter;
use Orion\Query\Sort;
use Orion\Query\TermFilter;
use Orion\Query\TermsFilter;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Support\Assert;

use function array_filter;
use function array_is_list;
use function array_map;
use function array_values;
use function ceil;
use function count;
use function floor;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function max;
use function min;
use function sprintf;
use function str_starts_with;

/**
 * The neutral query AST compiled to a Meilisearch search body: a pure function, so the same query always compiles to
 * the same body (and `compile_query` can show it without executing). Lexical clauses are filter expressions on the
 * shadow attributes {@see Documents} writes, never `q`: Meilisearch's `q` requires every word (or drops words), tolerates
 * typos and completes the last word, none of which is the portable match the conformance case defines (any token, as
 * analysed, exactly). A value is hex-encoded into its filter, so no value can change an expression's shape.
 */
final class QueryCompiler
{
    private const string LIVE = Documents::TOMBSTONE . ' = false';
    /** A clause that can match nothing (an empty match), as a filter that never holds beside {@see LIVE}. */
    private const string NEVER = '(' . Documents::TOMBSTONE . ' = true)';

    public function __construct(private readonly string $engine = MeilisearchEngine::NAME)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function compile(Query $query, IndexSchema $schema): array
    {
        if ($query->vector !== null && $query->clauses !== []) {
            throw UnsupportedClause::missing(Capability::HybridFusion, 'hybrid retrieval', $this->engine);
        }
        $flat = Documents::flatten($schema->fields);
        $body = ['filter' => [
            self::LIVE,
            ...array_map(fn(Clause $clause): string => $this->clause($clause, $flat), $query->clauses),
            ...array_map(fn(Filter $filter): string => $this->filter($filter, $flat), $query->filters),
        ]];
        $sorts = [];
        foreach ($query->sorts as $sort) {
            if ($sort->field !== Sort::SCORE) {
                $sorts[] = self::attribute($sort->field, $flat) . ':' . $sort->direction->value;
            }
        }
        if ($sorts !== []) {
            $body['sort'] = $sorts;
        }
        if ($query->facets !== []) {
            $body['facets'] = array_map(static fn(Facet $facet): string => Documents::shadow($facet->field), $query->facets);
        }
        $from = ($query->page->page - 1) * $query->page->size;
        if ($query->vector !== null) {
            // Semantic ranking over every candidate: the k nearest are the first k, so the page is cut at k.
            $body['vector'] = $query->vector->vector;
            $body['hybrid'] = ['embedder' => Documents::shadow($query->vector->field), 'semanticRatio' => 1.0];
            // A page past the k-th neighbour starts at k and holds nothing, so the window never exceeds k.
            $body['offset'] = min($from, $query->vector->k);
            $body['limit'] = min($query->page->size, max(0, $query->vector->k - $from));
        } else {
            $body['page'] = $query->page->page;
            $body['hitsPerPage'] = $query->page->size;
        }
        $body['showRankingScore'] = true;

        return $body;
    }

    /**
     * A native search body restricted by neutral filters, for the scoped escape hatch: the filters are ANDed with the
     * body's own (a string, or a list whose items are strings or lists of strings, Meilisearch's AND of ORs), after
     * the tombstone exclusion every search carries. Nothing in a search body reads past its filter (facets and
     * distinct values count within it), so no key is refused; what is not a search body is.
     *
     * @param array<array-key, mixed> $body
     * @param array<Filter> $scope
     * @return array<string, mixed>
     */
    public function scope(array $body, array $scope, IndexSchema $schema): array
    {
        foreach ($body as $key => $_) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('A Meilisearch native query is a search body: an object with string keys.');
            }
        }
        $existing = $body['filter'] ?? [];
        $existing = is_string($existing) ? [$existing] : $existing;
        if (!is_array($existing) || !array_is_list($existing)) {
            throw new InvalidArgumentException('A native filter is a string or a list of strings and lists of strings.');
        }
        foreach ($existing as $part) {
            if (!is_string($part) && !(is_array($part) && array_is_list($part) && array_filter($part, static fn($item): bool => !is_string($item)) === [])) {
                throw new InvalidArgumentException('A native filter is a string or a list of strings and lists of strings.');
            }
        }
        $flat = Documents::flatten($schema->fields);

        /** @var array<string, mixed> $body */
        return [...$body, 'filter' => [...$existing, self::LIVE, ...array_map(fn(Filter $filter): string => $this->filter($filter, $flat), array_values($scope))]];
    }

    /**
     * @param array<string, Field> $flat
     */
    public function filter(Filter $filter, array $flat): string
    {
        return match (true) {
            $filter instanceof TermFilter => self::term($filter->field, $filter->value, $flat),
            $filter instanceof TermsFilter => self::terms($filter, $flat),
            $filter instanceof RangeFilter => self::range($filter, $flat),
            $filter instanceof ExistsFilter => self::exists($filter->field, $flat),
            $filter instanceof BoolFilter => self::bool($filter, $flat),
            default => throw new LogicException('Unknown filter ' . $filter::class),
        };
    }

    /**
     * @param array<string, Field> $flat
     */
    private function clause(Clause $clause, array $flat): string
    {
        $parts = [];
        foreach ($clause->fields as $path => $_) {
            $parts[] = self::clauseOn($clause, (string) $path, self::field((string) $path, $flat));
        }

        return count($parts) === 1 ? $parts[0] : '(' . implode(' OR ', $parts) . ')';
    }

    private static function clauseOn(Clause $clause, string $path, Field $field): string
    {
        $attribute = Documents::shadow($path);
        $value = (string) $clause->value;
        if ($field->type !== FieldType::Text) {
            if ($clause->kind !== ClauseKind::Prefix) {
                return self::term($path, $clause->value, [$path => $field]);
            }
            if ($field->type !== FieldType::Keyword) {
                throw new InvalidArgumentException(sprintf('A prefix clause on %s, a %s field, is not available on Meilisearch: its numbers are not filtered as text.', Assert::show($path), $field->type->value));
            }

            return $attribute . ' STARTS WITH "' . Documents::hex($value) . '"';
        }
        $tokens = Matcher::tokens($value);

        return match ($clause->kind) {
            ClauseKind::Match => self::any(array_map(static fn(string $token): string => $attribute . ' CONTAINS "' . self::token($token) . '"', $tokens)),
            ClauseKind::Term => $attribute . ' CONTAINS "' . self::token($value) . '"',
            ClauseKind::Prefix => $attribute . ' CONTAINS "' . Documents::BOUNDARY . Documents::hex($value) . '"',
            ClauseKind::Phrase => $tokens === [] ? self::NEVER : $attribute . ' CONTAINS "' . Documents::BOUNDARY . implode(Documents::BOUNDARY, array_map(Documents::hex(...), $tokens)) . Documents::BOUNDARY . '"',
        };
    }

    /**
     * One whole token as it sits in a text field's shadow.
     */
    private static function token(string $token): string
    {
        return Documents::BOUNDARY . Documents::hex($token) . Documents::BOUNDARY;
    }

    /**
     * @param list<string> $expressions
     */
    private static function any(array $expressions): string
    {
        return match (count($expressions)) {
            0 => self::NEVER,
            1 => $expressions[0],
            default => '(' . implode(' OR ', $expressions) . ')',
        };
    }

    /**
     * `field = value`; a fractional value equals no integer, so on an integer field it matches nothing.
     *
     * @param array<string, Field> $flat
     */
    private static function term(string $path, mixed $value, array $flat): string
    {
        $field = self::field($path, $flat);
        if ($field->type === FieldType::Integer && self::fractional($value)) {
            return self::NEVER;
        }

        return self::attribute($path, $flat) . ' = ' . self::literal($field, $value);
    }

    /**
     * @param array<string, Field> $flat
     */
    private static function terms(TermsFilter $filter, array $flat): string
    {
        $field = self::field($filter->field, $flat);
        $values = $field->type === FieldType::Integer ? array_values(array_filter($filter->values, static fn(mixed $value): bool => !self::fractional($value))) : $filter->values;
        if ($values === []) {
            return self::NEVER;
        }

        return self::attribute($filter->field, $flat) . ' IN [' . implode(', ', array_map(static fn(mixed $value): string => self::literal($field, $value), $values)) . ']';
    }

    /**
     * Bounds in parentheses; on an integer field a fractional bound is rounded to the integers it admits.
     *
     * @param array<string, Field> $flat
     */
    private static function range(RangeFilter $filter, array $flat): string
    {
        $field = self::field($filter->field, $flat);
        $attribute = self::attribute($filter->field, $flat);
        $bounds = [];
        foreach (['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='] as $bound => $operator) {
            $value = $filter->$bound;
            if ($value === null) {
                continue;
            }
            if ($field->type === FieldType::Integer && self::fractional($value)) {
                $value = in_array($bound, ['gt', 'lte'], true) ? (int) floor((float) $value) : (int) ceil((float) $value);
            }
            $bounds[] = $attribute . ' ' . $operator . ' ' . self::literal($field, $value);
        }

        return match (count($bounds)) {
            0 => self::NEVER,
            1 => $bounds[0],
            default => '(' . implode(' AND ', $bounds) . ')',
        };
    }

    private static function fractional(mixed $value): bool
    {
        return is_numeric($value) && (float) $value !== floor((float) $value);
    }

    /**
     * @param array<string, Field> $flat
     */
    private static function exists(string $path, array $flat): string
    {
        $field = self::field($path, $flat);
        if ($field->fields === []) {
            return self::attribute($path, $flat) . ' EXISTS';
        }
        $leaves = [];
        foreach ($flat as $candidate => $leaf) {
            if (str_starts_with($candidate, $path . '.') && $leaf->fields === []) {
                $leaves[] = self::attribute($candidate, $flat) . ' EXISTS';
            }
        }

        return self::any($leaves);
    }

    /**
     * @param array<string, Field> $flat
     */
    private function bool(BoolFilter $filter, array $flat): string
    {
        $parts = array_map(fn(Filter $must): string => $this->filter($must, $flat), $filter->must);
        if ($filter->should !== []) {
            $parts[] = '(' . implode(' OR ', array_map(fn(Filter $should): string => $this->filter($should, $flat), $filter->should)) . ')';
        }
        foreach ($filter->mustNot as $mustNot) {
            $parts[] = 'NOT (' . $this->filter($mustNot, $flat) . ')';
        }

        return $parts === [] ? self::NEVER : '(' . implode(' AND ', $parts) . ')';
    }

    /**
     * A value as it is written into a filter for its field: numbers and booleans as such, a date as its instant,
     * anything else hex-encoded in quotes.
     */
    private static function literal(Field $field, mixed $value): string
    {
        return match ($field->type) {
            FieldType::Integer => (string) (is_scalar($value) ? (int) $value : 0),
            FieldType::Float => (string) (is_scalar($value) ? (float) $value : 0.0),
            FieldType::Boolean => $value === true || $value === 'true' ? 'true' : 'false',
            FieldType::Date => (string) (Documents::instant($value) ?? throw new InvalidArgumentException(sprintf('The field %s is a date; %s is not one.', Assert::show($field->name), Assert::show(is_scalar($value) ? (string) $value : '')))),
            default => '"' . Documents::hex(is_scalar($value) ? (string) $value : '') . '"',
        };
    }

    /**
     * @param array<string, Field> $flat
     */
    private static function attribute(string $path, array $flat): string
    {
        return self::field($path, $flat)->type === FieldType::Geo ? Documents::GEO : Documents::shadow($path);
    }

    /**
     * @param array<string, Field> $flat
     */
    private static function field(string $path, array $flat): Field
    {
        return $flat[$path] ?? throw new InvalidArgumentException(sprintf('The query names %s, which the index does not declare.', Assert::show($path)));
    }
}
