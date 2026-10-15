<?php

declare(strict_types=1);

namespace SilenZ\Beeline\Tests;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use SilenZ\Beeline\MetadataRegistry;
use SilenZ\Beeline\RouteDefinition;
use SilenZ\Beeline\RouteTable;

final class RouteTableTest extends TestCase
{
    public function testCollectFillsBothOutputsFromOneCall(): void
    {
        $table = new RouteTable(static function (array &$definitions, mixed &$metadata): void {
            $definitions = [new RouteDefinition('/a', 'a')];
            $metadata = ['middleware' => ['cors']];
        });

        $definitions = [];
        $metadata = null;
        $table->collect($definitions, $metadata);

        /** @var list<RouteDefinition> $definitions */
        static::assertCount(1, $definitions);
        static::assertSame('/a', $definitions[0]->path);
        static::assertSame(['middleware' => ['cors']], $metadata);
    }

    public function testCollectRunsAgainEveryTimeItIsCalled(): void
    {
        // Unlike earlier shapes of RouteTable, this class holds no state of its own and does no
        // memoizing — calling collect() twice runs $collect twice. Guaranteeing "once per request" is
        // Router's job, not RouteTable's; see Router::load().
        $calls = new ArrayObject();
        $table = new RouteTable(static function (array &$definitions) use ($calls): void {
            $calls->append(true);
            $definitions = [];
        });

        $definitions = [];
        $metadata = null;
        $table->collect($definitions, $metadata);

        /** @var list<RouteDefinition> $definitions */
        $table->collect($definitions, $metadata);

        static::assertCount(2, $calls);
    }

    public function testDefinitionsIsAConvenienceThatRunsCollectInFull(): void
    {
        $table = new RouteTable(static function (array &$definitions): void {
            $definitions = [new RouteDefinition('/a', 'a'), new RouteDefinition('/b', 'b')];
        });

        $definitions = $table->definitions();

        static::assertCount(2, $definitions);
        static::assertSame('/a', $definitions[0]->path);
        static::assertSame('/b', $definitions[1]->path);
    }

    public function testMetadataIsAConvenienceThatRunsCollectInFull(): void
    {
        $table = new RouteTable(static function (array &$definitions, mixed &$metadata): void {
            $metadata = ['middleware' => ['cors']];
        });

        static::assertSame(['middleware' => ['cors']], $table->metadata());
    }

    public function testMetadataDefaultsToNullWhenCollectNeverAssignsIt(): void
    {
        $table = new RouteTable(static function (): void {
            // Deliberately never assigns $metadata — this is still a valid collect, and the
            // default `mixed &$metadata = null` the caller passed in stays untouched.
        });

        static::assertNull($table->metadata());
        static::assertSame([], $table->definitions());
    }

    public function testCacheKeyIsReturnedAsGiven(): void
    {
        $table = new RouteTable(static function (): void {}, 'routes-v1');

        static::assertSame('routes-v1', $table->cacheKey());
    }

    public function testCacheKeyDefaultsToNull(): void
    {
        $table = new RouteTable(static function (): void {});

        static::assertNull($table->cacheKey());
    }

    public function testRegistryIsAPlainGetterWithNoSideEffect(): void
    {
        $registry = new MetadataRegistry();
        $calls = new ArrayObject();
        $table = new RouteTable(static function () use ($calls): void {
            $calls->append(true);
        }, registry: $registry);

        static::assertSame($registry, $table->registry());
        // Unlike earlier shapes of RouteTable, reading registry() never runs $collect.
        static::assertCount(0, $calls);
    }

    public function testRegistryDefaultsToNull(): void
    {
        $table = new RouteTable(static function (): void {});

        static::assertNull($table->registry());
    }
}
