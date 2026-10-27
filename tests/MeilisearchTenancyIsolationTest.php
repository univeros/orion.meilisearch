<?php

declare(strict_types=1);

namespace Orion\Meilisearch\Tests;

/**
 * The current Meilisearch line; set ORION_MEILISEARCH_URL, e.g. `http://127.0.0.1:7700`.
 */
final class MeilisearchTenancyIsolationTest extends MeilisearchIsolation
{
    protected static function urlVariable(): string
    {
        return 'ORION_MEILISEARCH_URL';
    }
}
