<?php

declare(strict_types=1);

namespace SilenZ\Beeline\Tests;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use SilenZ\Beeline\Cache\RouteCache;
use SilenZ\Beeline\Compiler;
use SilenZ\Beeline\MetadataRegistry;
use SilenZ\Beeline\NoMatch;
use SilenZ\Beeline\RouteDefinition;
use SilenZ\Beeline\RouteMatch;
use SilenZ\Beeline\Router;
use SilenZ\Beeline\RouteTable;
use SilenZ\Beeline\Tests\Fixtures\CallableRouteTable;

final class RouterTest extends TestCase
{
    /**
     * An in-memory cache, so these tests are about the router and not the file system.
     */
    private static function memoryCache(): RouteCache
    {
        return new class implements RouteCache {
            /** @var array<string, array<array-key, mixed>> */
            public array $entries = [];

            public function get(?string $key): ?array
            {
                return $this->entries[$key ?? ''] ?? null;
            }

            public function set(?string $key, array $compiled): void
            {
                $this->entries[$key ?? ''] = $compiled;
            }
        };
    }

    /**
     * @param callable(): iterable<int, RouteDefinition> $definitions
     */
    private static function table(callable $definitions, ?string $cacheKey = null): RouteTable
    {
        return new CallableRouteTable(static function (array &$d, mixed &$m) use ($definitions): void {
            $produced = $definitions();
            $d = is_array($produced) ? $produced : iterator_to_array($produced, preserve_keys: false);
        }, $cacheKey);
    }

    /**
     * @param list<mixed> $definitions
     */
    private static function plainTable(array $definitions, mixed $metadata = null): RouteTable
    {
        /**
         * @param array<array-key, mixed> &$d
         */
        $collect = static function (array &$d, mixed &$m) use ($definitions, $metadata): void {
            $d = $definitions;
            $m = $metadata;
        };

        return new CallableRouteTable($collect);
    }

    private static function route(RouteMatch|NoMatch $result): mixed
    {
        return $result instanceof RouteMatch ? $result->route : null;
    }

    public function testMatchesWithoutACache(): void
    {
        $router = new Router(self::table(static fn(): array => [new RouteDefinition('/users/{id}', 'user')]));

        $result = $router->match('/users/7');

        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame(['id' => '7'], $result->params);
    }

    public function testRoutesMayComeFromAGenerator(): void
    {
        $users =
            /** @return iterable<int, RouteDefinition> */
            static function (): iterable {
                yield new RouteDefinition('/users', 'users');
                yield new RouteDefinition('/users/{id}', 'user');
            };
        $router = new Router(self::table(
            /** @return iterable<int, RouteDefinition> */
            static function () use ($users): iterable {
                yield new RouteDefinition('/', 'home');
                yield from $users();
            },
        ));

        static::assertSame('home', self::route($router->match('/')));
        static::assertSame('user', self::route($router->match('/users/7')));
    }

    public function testNullCacheCompilesForEveryRouterInstance(): void
    {
        $calls = new ArrayObject();
        $define = static function () use ($calls): array {
            $calls->append(true);

            return [new RouteDefinition('/a', 'a')];
        };

        new Router(self::table($define))->match('/a');
        new Router(self::table($define))->match('/a');

        static::assertCount(2, $calls);
    }

    public function testRoutesAreDeclaredLazilyAndOnlyOncePerInstance(): void
    {
        $calls = new ArrayObject();
        $router = new Router(self::table(static function () use ($calls): array {
            $calls->append(true);

            return [new RouteDefinition('/a', 'a')];
        }));

        static::assertCount(0, $calls);

        $router->match('/a');
        $router->match('/b');

        static::assertCount(1, $calls);
    }

    public function testCachedRoutesAreNotDeclaredAgain(): void
    {
        $cache = self::memoryCache();
        $calls = new ArrayObject();
        $define = static function () use ($calls): array {
            $calls->append(true);

            return [new RouteDefinition('/a', 'a')];
        };

        new Router(self::table($define, 'routes'), $cache)->match('/a');
        $result = new Router(self::table($define, 'routes'), $cache)->match('/a');

        static::assertCount(1, $calls);
        static::assertSame('a', self::route($result));
    }

    public function testExistingEntryIsUsedEvenWhenTheDefinitionChanges(): void
    {
        $cache = self::memoryCache();
        new Router(self::table(static fn(): array => [new RouteDefinition('/old', 'old')], 'routes'), $cache)->match(
            '/old',
        );

        $router = new Router(self::table(static fn(): array => [new RouteDefinition('/new', 'new')], 'routes'), $cache);

        static::assertSame('old', self::route($router->match('/old')));
        static::assertNull(self::route($router->match('/new')));
    }

    public function testDifferentKeysKeepSeparateEntries(): void
    {
        $cache = self::memoryCache();
        $v1 = new Router(self::table(static fn(): array => [new RouteDefinition('/a', 'v1')], 'routes-v1'), $cache);
        $v2 = new Router(self::table(static fn(): array => [new RouteDefinition('/a', 'v2')], 'routes-v2'), $cache);

        static::assertSame('v1', self::route($v1->match('/a')));
        static::assertSame('v2', self::route($v2->match('/a')));
    }

    public function testANullKeyStillCachesWhenACacheIsConfigured(): void
    {
        $cache = self::memoryCache();
        $calls = new ArrayObject();
        $define = static function () use ($calls): array {
            $calls->append(true);

            return [new RouteDefinition('/a', 'a')];
        };

        new Router(self::table($define), $cache)->match('/a');
        new Router(self::table($define), $cache)->match('/a');

        static::assertCount(1, $calls);
    }

    public function testEntryFromAnIncompatibleVersionIsRecompiled(): void
    {
        $cache = self::memoryCache();
        $cache->set('routes', ['version' => 0] + Compiler::compile(self::plainTable([])));

        $router = new Router(self::table(static fn(): array => [new RouteDefinition('/a', 'a')], 'routes'), $cache);

        static::assertSame('a', self::route($router->match('/a')));
        static::assertSame(
            'a',
            self::route(new Router(self::table(static fn(): array => [], 'routes'), $cache)->match('/a')),
        );
    }

    public function testFilterIsPassedThrough(): void
    {
        $router = new Router(self::table(static fn(): array => [
            new RouteDefinition('/users', ['methods' => ['GET']]),
            new RouteDefinition('/users', ['methods' => ['POST']]),
        ]));

        $result = $router->match(
            '/users',
            static fn(RouteMatch $match): bool => $match->route === ['methods' => ['POST']],
        );

        static::assertSame(['methods' => ['POST']], self::route($result));
    }

    public function testDefinitionsReturnsTheRoutesAsDeclared(): void
    {
        $router = new Router(self::table(static fn(): array => [
            new RouteDefinition('/users', 'list'),
            new RouteDefinition('/users/{id}', 'show'),
        ]));

        $definitions = $router->table()->definitions();

        static::assertCount(2, $definitions);
        static::assertSame('/users', $definitions[0]->path);
        static::assertSame('/users/{id}', $definitions[1]->path);
    }

    public function testDefinitionsRunsTheCollectCallableAtMostOnce(): void
    {
        // RouteTable itself memoizes definitions()/metadata() now — see its class docs. router->table()
        // keeps returning the very same RouteTable instance, so calling definitions() on it twice still
        // only declares once.
        $calls = new ArrayObject();
        $router = new Router(self::table(static function () use ($calls): array {
            $calls->append(true);

            return [new RouteDefinition('/a', 'a')];
        }));

        $router->table()->definitions();
        $router->table()->definitions();

        static::assertCount(1, $calls);
    }

    public function testDefinitionsIgnoresTheCache(): void
    {
        $cache = self::memoryCache();
        $router = new Router(self::table(static fn(): array => [new RouteDefinition('/a', 'first')], 'routes'), $cache);
        $router->match('/a'); // populates the cache

        $laterRouter = new Router(self::table(static fn(): array => [new RouteDefinition(
            '/a',
            'second',
        )], 'routes'), $cache);

        static::assertSame('first', self::route($laterRouter->match('/a')));
        static::assertSame('second', $laterRouter->table()->definitions()[0]->metadata);
    }

    public function testMetadataIsNullByDefault(): void
    {
        $router = new Router(self::table(static fn(): array => [new RouteDefinition('/a', 'a')]));

        static::assertNull($router->metadata());
    }

    public function testMetadataComesFromTheCacheWithoutDeclaringAgain(): void
    {
        $cache = self::memoryCache();
        $calls = new ArrayObject();
        $table = static fn(string $middleware): RouteTable => new CallableRouteTable(static function (
            array &$d,
            mixed &$m,
        ) use ($calls, $middleware): void {
            $calls->append('definitions');
            $d = [new RouteDefinition('/a', 'a')];

            $calls->append('metadata');
            $m = ['middleware' => [$middleware]];
        }, 'routes');

        static::assertSame(['middleware' => ['first']], new Router($table('first'), $cache)->metadata());
        // Answered from the cache: neither the routes nor the table metadata are produced again.
        static::assertSame(['middleware' => ['first']], new Router($table('second'), $cache)->metadata());
        static::assertSame(['definitions', 'metadata'], $calls->getArrayCopy());
    }

    public function testMetadataMayBeGivenAsAPlainValue(): void
    {
        $router = new Router(self::plainTable([], ['middleware' => ['cors']]));

        static::assertSame(['middleware' => ['cors']], $router->metadata());
    }

    public function testDefinitionsMayBeGivenAsAPlainArray(): void
    {
        $router = new Router(self::plainTable([new RouteDefinition('/a', 'a')]));

        static::assertSame('a', self::route($router->match('/a')));
    }

    public function testMetadataIsNeverMatched(): void
    {
        $router = new Router(self::plainTable([], 'table'));

        static::assertInstanceOf(NoMatch::class, $router->match('/'));
        static::assertSame([], $router->routes());
    }

    public function testARegistryBackedTableStillDeclaresOnACacheHit(): void
    {
        $cache = self::memoryCache();
        $calls = new ArrayObject();
        $registry = new MetadataRegistry();
        $table = static fn(): RouteTable => new CallableRouteTable(
            static function (array &$d, mixed &$m) use ($calls, $registry): void {
                $calls->append(true);
                $d = [new RouteDefinition('/a', $registry->register('a'))];
                $m = $registry->register(['middleware' => []]);
            },
            'routes',
            $registry,
        );

        new Router($table(), $cache)->match('/a'); // cache miss: declares once
        new Router($table(), $cache)->match('/a'); // cache hit, but registry-backed: declares again

        static::assertCount(2, $calls);
    }

    public function testATableWithoutARegistryDoesNotDeclareOnACacheHit(): void
    {
        $cache = self::memoryCache();
        $calls = new ArrayObject();
        $define = static function () use ($calls): array {
            $calls->append(true);

            return [new RouteDefinition('/a', 'a')];
        };

        new Router(self::table($define, 'routes'), $cache)->match('/a');
        new Router(self::table($define, 'routes'), $cache)->match('/a');

        static::assertCount(1, $calls);
    }
}
