<?php

declare(strict_types=1);

namespace Orion\Meilisearch\Tests;

use InvalidArgumentException;
use Orion\Adapter\Capability;
use Orion\Meilisearch\MeilisearchEngine;
use PHPUnit\Framework\TestCase;

final class ConnectTest extends TestCase
{
    public function testAnEngineConnectsToTheUrlItIsGiven(): void
    {
        $engine = MeilisearchEngine::connect('http://127.0.0.1:57700');

        self::assertTrue($engine->supports(Capability::AsyncWrites));
        self::assertFalse($engine->supports(Capability::NativeAliases));
        self::assertFalse($engine->supports(Capability::HybridFusion));
        self::assertFalse($engine->supports(Capability::NestedDocuments));
    }

    public function testConnectingNeedsAUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MeilisearchEngine::connect('');
    }
}
