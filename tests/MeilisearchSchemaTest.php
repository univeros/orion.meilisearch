<?php

declare(strict_types=1);

namespace Orion\Meilisearch\Tests;

/**
 * The current Meilisearch line; set ORION_MEILISEARCH_URL.
 */
final class MeilisearchSchemaTest extends MeilisearchSchema
{
    protected static function urlVariable(): string
    {
        return 'ORION_MEILISEARCH_URL';
    }
}
