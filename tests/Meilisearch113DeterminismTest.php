<?php

declare(strict_types=1);

namespace Orion\Meilisearch\Tests;

/**
 * Meilisearch 1.13, the oldest line the adapter supports; set ORION_MEILISEARCH113_URL.
 */
final class Meilisearch113DeterminismTest extends MeilisearchDeterminism
{
    protected static function urlVariable(): string
    {
        return 'ORION_MEILISEARCH113_URL';
    }
}
