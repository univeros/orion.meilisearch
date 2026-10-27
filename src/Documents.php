<?php

declare(strict_types=1);

namespace Orion\Meilisearch;

use DateTimeImmutable;
use DateTimeZone;
use Orion\Adapter\InMemory\Matcher;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Support\Assert;
use Orion\Write\Document;
use Orion\Write\Tombstone;
use Throwable;

use function array_filter;
use function array_is_list;
use function array_map;
use function array_slice;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function count;
use function gmdate;
use function hex2bin;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function max;
use function preg_match;
use function rtrim;
use function sprintf;
use function strlen;
use function str_replace;
use function strtr;
use function substr;

/**
 * An Orion document as a Meilisearch document: the source as written under {@see SOURCE}, Orion's own attributes,
 * and one shadow attribute per declared field for the compiler to filter, sort and facet on. Shadows carry a text
 * field's tokens as one delimited string and a keyword's value hex-encoded, so the engine's case-insensitive filters
 * stay exact; a date as its instant; a vector under `_vectors` for its embedder; a geo point as `_geo`. The id is
 * encoded to what the engine accepts (`d` and base64url), the original kept beside it.
 */
final class Documents
{
    public const string ID = 'id';
    public const string ORION_ID = 'orion_id';
    public const string VERSION = 'orion_version';
    public const string TOMBSTONE = 'orion_tombstone';
    public const string SOURCE = 'orion_src';
    public const string VECTORS = '_vectors';
    public const string GEO = '_geo';
    public const string SHADOW = 'orion_f_';
    private const string ID_PREFIX = 'd';

    public static function id(string $orionId): string
    {
        return self::ID_PREFIX . rtrim(strtr(base64_encode($orionId), '+/', '-_'), '=');
    }

    public static function orionId(string $id): string
    {
        return (string) base64_decode(strtr(substr($id, 1), '-_', '+/'), true);
    }

    public static function shadow(string $path): string
    {
        return self::SHADOW . str_replace('.', '__', $path);
    }

    public static function hex(string $value): string
    {
        return bin2hex($value);
    }

    /** Between and around a text field's tokens: a letter no hex digit is, which the engine never trims as it trims a space. */
    public const string BOUNDARY = 'x';
    /** The engine stops matching a filterable string somewhere past 460 bytes; a shadow string stays well under. */
    public const int CHUNK_BYTES = 400;
    /** Tokens shared by neighbouring chunks, so a phrase up to this long sits whole in one of them. */
    public const int CHUNK_OVERLAP = 8;
    /** A keyword's value, in bytes, hex-encoded within {@see CHUNK_BYTES}. */
    public const int KEYWORD_BYTES = 200;
    /** What the engine accepts as a document id, in bytes, once encoded. */
    public const int ID_BYTES = 511;

    /**
     * A text value's tokens, each hex-encoded, as one delimited string: `x72756ex73686f65x`; a long text's as several
     * such strings, each within {@see CHUNK_BYTES} and overlapping the next by {@see CHUNK_OVERLAP} tokens, since the
     * engine stops matching a filterable string past a length and matches any string of a list.
     *
     * @return string|list<string>
     */
    public static function tokens(string $text): string|array
    {
        $tokens = array_map(bin2hex(...), Matcher::tokens($text));
        $whole = self::BOUNDARY . implode(self::BOUNDARY, $tokens) . self::BOUNDARY;
        if (strlen($whole) <= self::CHUNK_BYTES) {
            return $whole;
        }
        $chunks = [];
        $start = 0;
        while ($start < count($tokens)) {
            $end = $start;
            $length = 1;
            while ($end < count($tokens) && ($end === $start || $length + strlen($tokens[$end]) + 1 <= self::CHUNK_BYTES)) {
                $length += strlen($tokens[$end]) + 1;
                ++$end;
            }
            $chunks[] = self::BOUNDARY . implode(self::BOUNDARY, array_slice($tokens, $start, $end - $start)) . self::BOUNDARY;
            if ($end >= count($tokens)) {
                break;
            }
            $start = max($start + 1, $end - self::CHUNK_OVERLAP);
        }

        return $chunks;
    }

    /**
     * Why an id cannot be indexed, or null: encoded, it must fit what the engine accepts.
     */
    public static function invalidId(string $id): ?string
    {
        return strlen(self::id($id)) > self::ID_BYTES
            ? sprintf('The id %s is longer than the engine accepts once encoded (%d bytes).', Assert::show($id), self::ID_BYTES)
            : null;
    }

    /**
     * An ISO 8601 date or date-time as its Unix timestamp; anything else (a relative phrase included) is not a date.
     */
    public static function instant(mixed $value): ?int
    {
        if (!is_scalar($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value) !== 1) {
            return null;
        }
        try {
            // A value without a zone is UTC whatever the process default, so writers and readers agree.
            return (int) (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format('U');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<Field> $fields
     * @return array<string, Field> by dotted path
     */
    public static function flatten(array $fields, string $prefix = ''): array
    {
        $flat = [];
        foreach ($fields as $field) {
            $flat[$prefix . $field->name] = $field;
            $flat = [...$flat, ...self::flatten($field->fields, $prefix . $field->name . '.')];
        }

        return $flat;
    }

    /**
     * @return array<string, mixed>
     */
    public static function encode(Document $doc, IndexSchema $schema): array
    {
        $flat = self::flatten($schema->fields);
        $matcher = new Matcher($flat);
        $encoded = [
            self::ID => self::id($doc->id),
            self::ORION_ID => $doc->id,
            self::VERSION => $doc->version,
            self::TOMBSTONE => false,
            self::SOURCE => $matcher->withoutVectors($doc->fields),
        ];
        $vectors = [];
        foreach ($flat as $path => $field) {
            $values = $matcher->values($doc->fields, $path);
            if ($field->type === FieldType::Vector) {
                $vectors[self::shadow($path)] = $values === [] ? null : $values[0];
                continue;
            }
            if ($values === [] || $field->fields !== []) {
                continue;
            }
            if ($field->type === FieldType::Geo) {
                $point = is_array($values[0]) ? $values[0] : [];
                $encoded[self::GEO] = ['lat' => (float) ($point['lat'] ?? 0), 'lng' => (float) ($point['lon'] ?? 0)];
                continue;
            }
            $shadows = array_map(static fn(mixed $value): mixed => self::shadowValue($field, $value), $values);
            if ($field->type === FieldType::Text) {
                $strings = [];
                foreach ($shadows as $shadow) {
                    $strings = [...$strings, ...(is_array($shadow) ? $shadow : [$shadow])];
                }
                $shadows = $strings;
            }
            $encoded[self::shadow($path)] = count($shadows) === 1 ? $shadows[0] : $shadows;
        }
        if ($vectors !== []) {
            $encoded[self::VECTORS] = $vectors;
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    public static function tombstone(Tombstone $tombstone, IndexSchema $schema): array
    {
        $vectors = [];
        foreach (self::flatten($schema->fields) as $path => $field) {
            if ($field->type === FieldType::Vector) {
                $vectors[self::shadow($path)] = null;
            }
        }

        return [
            self::ID => self::id($tombstone->id),
            self::ORION_ID => $tombstone->id,
            self::VERSION => $tombstone->version,
            self::TOMBSTONE => true,
            ...($vectors === [] ? [] : [self::VECTORS => $vectors]),
        ];
    }

    /**
     * A value as its shadow: what the compiler writes into a filter for the same field and value.
     */
    public static function shadowValue(Field $field, mixed $value): mixed
    {
        return match ($field->type) {
            FieldType::Text => self::tokens(is_scalar($value) ? (string) $value : ''),
            FieldType::Integer => is_numeric($value) ? (int) $value : 0,
            FieldType::Float => is_numeric($value) ? (float) $value : 0.0,
            FieldType::Boolean => $value === true || $value === 'true',
            FieldType::Date => self::instant($value) ?? 0,
            default => self::hex(is_scalar($value) ? (string) $value : ''),
        };
    }

    /**
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    public static function source(array $hit): array
    {
        /** @var array<string, mixed> */
        return is_array($hit[self::SOURCE] ?? null) ? $hit[self::SOURCE] : [];
    }

    /**
     * A facet distribution's keys (the shadow values) as the field's values.
     *
     * @param array<array-key, mixed> $distribution
     * @return array<string, int>
     */
    public static function facet(array $distribution, Field $field): array
    {
        $counts = [];
        foreach ($distribution as $key => $count) {
            $key = (string) $key;
            $value = match ($field->type) {
                FieldType::Keyword => (string) hex2bin($key),
                FieldType::Date => gmdate('Y-m-d\TH:i:s\Z', (int) $key),
                FieldType::Float => (string) (float) $key,
                FieldType::Integer => (string) (int) $key,
                default => $key,
            };
            $counts[$value] = is_numeric($count) ? (int) $count : 0;
        }

        return $counts;
    }

    /**
     * Counts keyed by source values, keyed as {@see facet()} keys them, so both paths agree.
     *
     * @param array<array-key, int> $counts
     * @return array<string, int>
     */
    public static function facetKeys(array $counts, Field $field): array
    {
        $keyed = [];
        foreach ($counts as $value => $count) {
            $keyed[self::facetKey($field, $value)] = ($keyed[self::facetKey($field, $value)] ?? 0) + $count;
        }

        return $keyed;
    }

    public static function facetKey(Field $field, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return match ($field->type) {
            FieldType::Date => gmdate('Y-m-d\TH:i:s\Z', self::instant($value) ?? 0),
            FieldType::Float => (string) (is_scalar($value) ? (float) $value : 0.0),
            FieldType::Integer => (string) (is_scalar($value) ? (int) $value : 0),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * Why a document cannot be indexed, or null: a key the fields do not declare (looking into objects), or a value
     * the field's type rejects, as a strictly mapped engine would.
     *
     * @param array<array-key, mixed> $source
     * @param list<Field> $fields
     */
    public static function invalid(array $source, array $fields, string $prefix = ''): ?string
    {
        $byName = Field::byName($fields);
        foreach ($source as $key => $value) {
            $path = $prefix . $key;
            $field = $byName[(string) $key] ?? null;
            if ($field === null) {
                return sprintf('%s is not a declared field.', Assert::show($path));
            }
            if ($value === null) {
                continue;
            }
            if ($field->fields !== []) {
                foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $child) {
                    $reason = is_array($child) ? self::invalid($child, $field->fields, $path . '.') : sprintf('%s must be an object.', Assert::show($path));
                    if ($reason !== null) {
                        return $reason;
                    }
                }
                continue;
            }
            if (!self::accepts($field, $value)) {
                return sprintf('%s does not accept that value for a %s field.', Assert::show($path), $field->type->value);
            }
        }

        return null;
    }

    private static function accepts(Field $field, mixed $value): bool
    {
        if ($field->type === FieldType::Vector) {
            return is_array($value) && array_is_list($value) && count($value) === $field->dims
                && array_filter($value, static fn($component): bool => !is_int($component) && !is_float($component)) === [];
        }
        if ($field->type === FieldType::Geo) {
            return is_array($value) && is_numeric($value['lat'] ?? null) && is_numeric($value['lon'] ?? null);
        }
        foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $item) {
            $ok = match ($field->type) {
                FieldType::Integer => is_int($item) || (is_string($item) && preg_match('/^-?\d+\z/', $item) === 1),
                FieldType::Float => is_int($item) || is_float($item) || (is_string($item) && is_numeric($item)),
                FieldType::Boolean => is_bool($item) || $item === 'true' || $item === 'false',
                FieldType::Date => self::instant($item) !== null,
                FieldType::Keyword => is_scalar($item) && strlen((string) $item) <= self::KEYWORD_BYTES,
                default => is_scalar($item),
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }
}
