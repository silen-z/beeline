<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests;

use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\MetadataRegistry;
use stdClass;

final class MetadataRegistryTest extends TestCase
{
    public function testMetadataIsStoredAndReadBackById(): void
    {
        $registry = new MetadataRegistry();
        $metadata = ['handler' => 'show', 'middleware' => []];

        $id = $registry->register($metadata);

        static::assertSame($metadata, $registry->get($id));
    }

    public function testIdsAreAssignedInRegistrationOrder(): void
    {
        $registry = new MetadataRegistry();

        $firstId = $registry->register(['handler' => 'first']);
        $secondId = $registry->register(['handler' => 'second']);

        static::assertSame(['handler' => 'first'], $registry->get($firstId));
        static::assertSame(['handler' => 'second'], $registry->get($secondId));
        static::assertNotSame($firstId, $secondId);
    }

    public function testMetadataMayHoldRealInstances(): void
    {
        $registry = new MetadataRegistry();
        $handler = new stdClass();

        $id = $registry->register(['handler' => $handler]);

        /** @var array{handler: mixed} $metadata */
        $metadata = $registry->get($id);

        static::assertSame($handler, $metadata['handler']);
    }

    public function testRoutesAndTableMetadataShareOneIdSpace(): void
    {
        $registry = new MetadataRegistry();

        $routeId = $registry->register('a route');
        $tableId = $registry->register('the table');
        $anotherRouteId = $registry->register('another route');

        static::assertSame(['a route', 'the table', 'another route'], [
            $registry->get($routeId),
            $registry->get($tableId),
            $registry->get($anotherRouteId),
        ]);
        static::assertNotSame($routeId, $tableId);
        static::assertNotSame($tableId, $anotherRouteId);
    }
}
