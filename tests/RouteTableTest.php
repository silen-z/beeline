<?php

declare(strict_types=1);

namespace SilenZ\Beeline\Tests;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use SilenZ\Beeline\MetadataRegistry;
use SilenZ\Beeline\RouteDefinition;
use SilenZ\Beeline\Tests\Fixtures\CallableRouteTable;

final class RouteTableTest extends TestCase
{
    public function testDefinitionsAndMetadataComeFromOneCollect(): void
    {
        $table = new CallableRouteTable(static function (array &$definitions, mixed &$metadata): void {
            $definitions = [new RouteDefinition('/a', 'a')];
            $metadata = ['middleware' => ['cors']];
        });

        $definitions = $table->definitions();

        static::assertCount(1, $definitions);
        static::assertSame('/a', $definitions[0]->path);
        static::assertSame(['middleware' => ['cors']], $table->metadata());
    }

    public function testCollectRunsAtMostOnceNoMatterHowManyTimesDefinitionsOrMetadataAreCalled(): void
    {
        // Unlike earlier shapes of RouteTable, this class now memoizes both outputs itself: collect()
        // runs once, on whichever of definitions()/metadata() is called first, never again after —
        // regardless of how many more times either is called.
        $calls = new ArrayObject();
        $table = new CallableRouteTable(static function (array &$definitions) use ($calls): void {
            $calls->append(true);
            $definitions = [];
        });

        $table->definitions();
        $table->metadata();
        $table->definitions();

        static::assertCount(1, $calls);
    }

    public function testDefinitionsReturnsWhatCollectProduced(): void
    {
        $table = new CallableRouteTable(static function (array &$definitions): void {
            $definitions = [new RouteDefinition('/a', 'a'), new RouteDefinition('/b', 'b')];
        });

        $definitions = $table->definitions();

        static::assertCount(2, $definitions);
        static::assertSame('/a', $definitions[0]->path);
        static::assertSame('/b', $definitions[1]->path);
    }

    public function testMetadataReturnsWhatCollectProduced(): void
    {
        $table = new CallableRouteTable(static function (array &$definitions, mixed &$metadata): void {
            $metadata = ['middleware' => ['cors']];
        });

        static::assertSame(['middleware' => ['cors']], $table->metadata());
    }

    public function testMetadataDefaultsToNullWhenCollectNeverAssignsIt(): void
    {
        $table = new CallableRouteTable(static function (): void {
            // Deliberately never assigns $metadata — this is still a valid collect, and the
            // default `mixed &$metadata = null` the caller passed in stays untouched.
        });

        static::assertNull($table->metadata());
        static::assertSame([], $table->definitions());
    }

    public function testCacheKeyIsReturnedAsGiven(): void
    {
        $table = new CallableRouteTable(static function (): void {}, 'routes-v1');

        static::assertSame('routes-v1', $table->cacheKey());
    }

    public function testCacheKeyDefaultsToNull(): void
    {
        $table = new CallableRouteTable(static function (): void {});

        static::assertNull($table->cacheKey());
    }

    public function testRegistryIsAPlainGetterWithNoSideEffect(): void
    {
        $registry = new MetadataRegistry();
        $calls = new ArrayObject();
        $table = new CallableRouteTable(static function () use ($calls): void {
            $calls->append(true);
        }, registry: $registry);

        static::assertSame($registry, $table->registry());
        // Unlike earlier shapes of RouteTable, reading registry() never runs $collect.
        static::assertCount(0, $calls);
    }

    public function testRegistryDefaultsToNull(): void
    {
        $table = new CallableRouteTable(static function (): void {});

        static::assertNull($table->registry());
    }
}
