<?php

declare(strict_types=1);

namespace Orion\Meilisearch\Tests;

/**
 * Meilisearch 1.13; set ORION_MEILISEARCH113_URL.
 */
final class Meilisearch113SchemaTest extends MeilisearchSchema
{
    protected static function urlVariable(): string
    {
        return 'ORION_MEILISEARCH113_URL';
    }
}
