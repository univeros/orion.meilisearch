<?php

declare(strict_types=1);

namespace Orion\Meilisearch\Tests;

use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Testing\TenancyIsolationTestCase;

/**
 * Runs the case against the Meilisearch node at the URL in {@see urlVariable()}; skipped when it is unset.
 */
abstract class MeilisearchIsolation extends TenancyIsolationTestCase
{
    abstract protected static function urlVariable(): string;

    protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return MeilisearchServer::engine(static::urlVariable(), $this);
    }

    protected function nativeMatchAll(): callable
    {
        return MeilisearchServer::matchAll();
    }

    protected function nativeShapes(): array
    {
        return MeilisearchServer::isolationShapes();
    }

    protected function nativeProbes(): array
    {
        return MeilisearchServer::isolationProbes();
    }
}
