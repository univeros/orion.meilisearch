<?php

declare(strict_types=1);

namespace Orion\Meilisearch\Tests;

/**
 * Meilisearch 1.13, the oldest line the adapter supports; set ORION_MEILISEARCH113_URL.
 */
final class Meilisearch113TenancyIsolationTest extends MeilisearchIsolation
{
    protected static function urlVariable(): string
    {
        return 'ORION_MEILISEARCH113_URL';
    }
}
