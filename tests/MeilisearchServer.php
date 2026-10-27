<?php

declare(strict_types=1);

namespace Orion\Meilisearch\Tests;

use Orion\Meilisearch\MeilisearchEngine;
use PHPUnit\Framework\TestCase;

use function getenv;

/**
 * The Meilisearch node the service-backed suites run against: ORION_MEILISEARCH_URL (the current line) or
 * ORION_MEILISEARCH113_URL (1.13, the oldest line the adapter supports), one client for both.
 */
final class MeilisearchServer
{
    public static function engine(string $variable, TestCase $test): MeilisearchEngine
    {
        $url = getenv($variable);
        if ($url === false) {
            $test->markTestSkipped($variable . ' is not set.');
        }

        return MeilisearchEngine::connect($url);
    }

    public static function matchAll(): callable
    {
        return static fn(): array => ['limit' => 100];
    }

    /**
     * Native bodies the escape hatch accepts under a scope, each matching every seeded document.
     *
     * @return array<string, callable(): array<string, mixed>>
     */
    public static function shapes(): array
    {
        return [
            'a search term' => static fn(): array => ['q' => 'running', 'limit' => 100],
            'own filter' => static fn(): array => ['filter' => 'orion_f_price > 0', 'limit' => 100],
            'a filter list' => static fn(): array => ['filter' => [['orion_f_price > 0', 'orion_f_stock > 0']], 'limit' => 100],
            'facets' => static fn(): array => ['facets' => ['orion_f_brand'], 'page' => 1, 'hitsPerPage' => 100],
            'a vector search' => static fn(): array => ['vector' => [1.0, 0.0, 0.0], 'hybrid' => ['embedder' => 'orion_f_embedding', 'semanticRatio' => 1.0], 'limit' => 100],
            'a sorted page' => static fn(): array => ['sort' => ['orion_f_price:desc'], 'page' => 1, 'hitsPerPage' => 100],
        ];
    }

    /**
     * Native bodies that would reach the foreign tenant unscoped.
     *
     * @return array<string, callable(): array<string, mixed>>
     */
    public static function isolationShapes(): array
    {
        return [
            'the foreign tenant by name' => static fn(): array => ['filter' => 'orion_f_org_id = "676c6f626578"', 'limit' => 100],
            'the foreign tenant in a filter list' => static fn(): array => ['filter' => [['orion_f_org_id = "676c6f626578"', 'orion_f_org_id = "61636d65"']], 'limit' => 100],
            'a search term' => static fn(): array => ['q' => 'globex', 'limit' => 100],
            'everything, facetted' => static fn(): array => ['facets' => ['orion_f_org_id', 'orion_f_brand'], 'page' => 1, 'hitsPerPage' => 100],
            'everything, sorted' => static fn(): array => ['sort' => ['orion_f_sku:asc'], 'page' => 1, 'hitsPerPage' => 100],
            'tombstones asked for' => static fn(): array => ['filter' => 'orion_tombstone = true', 'limit' => 100],
            'every attribute asked for' => static fn(): array => ['attributesToRetrieve' => ['*'], 'retrieveVectors' => true, 'limit' => 100],
        ];
    }

    /**
     * Vector searches aimed at the foreign tenant's nearest embedding: scoped, each must still return the tenant's own
     * nearest neighbour.
     *
     * @return array<string, callable(): array<string, mixed>>
     */
    public static function isolationProbes(): array
    {
        $vector = ['vector' => [0.0, 1.0, 0.0], 'hybrid' => ['embedder' => 'orion_f_embedding', 'semanticRatio' => 1.0]];

        return [
            'the nearest neighbour' => static fn(): array => [...$vector, 'limit' => 1],
            'the nearest neighbour with a filter' => static fn(): array => [...$vector, 'filter' => 'orion_f_sku EXISTS', 'limit' => 1],
            'the nearest neighbour on a page' => static fn(): array => [...$vector, 'offset' => 0, 'limit' => 1, 'showRankingScore' => true],
        ];
    }
}
