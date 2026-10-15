<?php

declare(strict_types=1);

namespace SilenZ\Beeline\Benchmarks;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector as FastRouteCollector;
use LogicException;
use SilenZ\Beeline\Cache\FileCache;
use SilenZ\Beeline\Compiler;
use SilenZ\Beeline\Matcher;
use SilenZ\Beeline\RouteDefinition;
use SilenZ\Beeline\Router;
use SilenZ\Beeline\RouteTable;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route as SymfonyRoute;
use Symfony\Component\Routing\RouteCollection;

use function FastRoute\cachedDispatcher;
use function FastRoute\simpleDispatcher;
use function file_put_contents;
use function is_file;
use function preg_match;
use function preg_replace;
use function str_contains;
use function unlink;
use function usort;

/**
 * Builds equivalent routers from one fixture. Route metadata is the route's index in the fixture,
 * so results of both routers can be compared.
 *
 * Symfony here means bare `CompiledUrlMatcher` construction, the fair comparison against this
 * router's own bare `Matcher` — neither wraps itself in an application-level router that decides
 * when to compile versus load from cache. That comparison (`Routes`/`LazyRoutes` against Symfony's
 * actual `Router`) lives in switchyard instead, alongside the classes it's fair to compare.
 */
final class Routers
{
    /**
     * @param list<string> $routes
     *
     * @return list<RouteDefinition>
     */
    public static function flatRoutes(array $routes): array
    {
        $definitions = [];
        foreach ($routes as $index => $route) {
            $definitions[] = new RouteDefinition($route, $index);
        }

        return $definitions;
    }

    /**
     * @param list<string> $routes
     */
    public static function flat(array $routes): Matcher
    {
        return new Matcher(Compiler::compile(self::table(self::flatRoutes($routes))));
    }

    /**
     * @param list<RouteDefinition> $definitions
     */
    private static function table(array $definitions): RouteTable
    {
        return new RouteTable(static function (array &$d, mixed &$metadata) use ($definitions): void {
            $d = $definitions;
        });
    }

    public const string CACHE_DIRECTORY = __DIR__ . '/../var/bench-cache';

    /**
     * Writes fresh caches of every router for a fixture. This router's and Symfony's are stored in
     * {@see CACHE_DIRECTORY}, this router's under the fixture name as key, Symfony's at
     * {@see symfonyFile()}; FastRoute's cache file is returned since it's named by the caller, not
     * derived from the fixture name the way the other two are.
     *
     * @return string the path of FastRoute's cache file
     */
    public static function writeCaches(string $fixture): string
    {
        $routes = Fixtures::get($fixture)['routes'];
        $fastRouteFile = self::CACHE_DIRECTORY . '/' . $fixture . '.fast-route.php';

        new FileCache(self::CACHE_DIRECTORY)->set($fixture, Compiler::compile(self::table(self::flatRoutes($routes))));

        file_put_contents(
            self::symfonyFile($fixture),
            new CompiledUrlMatcherDumper(self::symfonyCollection($routes))->dump(),
        );

        // FastRoute only writes its cache when the file does not exist yet.
        if (is_file($fastRouteFile)) {
            unlink($fastRouteFile);
        }
        self::fastRoute($routes, $fastRouteFile);

        return $fastRouteFile;
    }

    /**
     * This router as an application uses it, for a fixture whose cache was written by
     * {@see writeCaches()}. Declaring the routes would mean the cache was not used, so it fails.
     */
    public static function cachedFlat(string $fixture): Router
    {
        return new Router(new RouteTable(static function (): never {
            throw new LogicException('Cache entry missing.');
        }, $fixture), new FileCache(self::CACHE_DIRECTORY));
    }

    /**
     * @param list<string> $routes
     */
    public static function fastRoute(array $routes, ?string $cacheFile = null): Dispatcher
    {
        $define = static function (FastRouteCollector $collector) use ($routes): void {
            foreach (self::ordered($routes) as $index => $route) {
                $collector->addRoute('GET', self::toFastRoutePattern($route), $index);
            }
        };

        if ($cacheFile === null) {
            return simpleDispatcher($define);
        }

        return cachedDispatcher($define, ['cacheFile' => $cacheFile]);
    }

    /**
     * Translates `{name*}` / `{name+}` catch-alls into FastRoute's regex placeholders.
     */
    public static function toFastRoutePattern(string $route): string
    {
        return (string) preg_replace(['#/\{(\w+)\*\}$#', '#\{(\w+)\+\}$#'], ['[/{$1:.*}]', '{$1:.+}'], $route);
    }

    /**
     * A bare `CompiledUrlMatcher`, built fresh in memory — this router's own `Routers::flat()`,
     * nothing wraps either in a cache-aware router.
     *
     * @param list<string> $routes
     */
    public static function symfony(array $routes): CompiledUrlMatcher
    {
        $compiled = new CompiledUrlMatcherDumper(self::symfonyCollection($routes))->getCompiledRoutes();

        return new CompiledUrlMatcher($compiled, new RequestContext());
    }

    /**
     * Where a fixture's Symfony cache file lives, written by {@see writeCaches()} — a pure path, like
     * FastRoute's cache file name, so a caller can read it back without writing it again.
     */
    public static function symfonyFile(string $fixture): string
    {
        return self::CACHE_DIRECTORY . '/' . $fixture . '.symfony.php';
    }

    /**
     * @param list<string> $routes
     */
    private static function symfonyCollection(array $routes): RouteCollection
    {
        $collection = new RouteCollection();
        foreach (self::ordered($routes) as $index => $route) {
            $symfony = new SymfonyRoute(
                (string) preg_replace('#\{(\w+)[*+]\}$#', replacement: '{$1}', subject: $route),
                methods: ['GET'],
            );
            $m = [];
            if (preg_match('#\{(\w+)([*+])\}$#', $route, $m) === 1) {
                $symfony->setRequirement($m[1], $m[2] === '+' ? '.+' : '.*');
            }
            $collection->add('r' . $index, $symfony);
        }

        return $collection;
    }

    /**
     * FastRoute and Symfony both resolve overlaps by registration order and reject (or shadow) a
     * static route registered after a variable route that would swallow it. Registering static
     * routes first, then parameter routes, then catch-alls gives both the same static > parameter >
     * catch-all precedence this router has.
     *
     * @param list<string> $routes
     *
     * @return array<int, string> original index => pattern
     */
    private static function ordered(array $routes): array
    {
        $rank = static fn(string $route): int => match (true) {
            preg_match('#\{\w+[*+]\}$#', $route) === 1 => 2,
            str_contains($route, '{') => 1,
            default => 0,
        };

        $indexes = [];
        foreach ($routes as $index => $route) {
            $indexes[] = $index;
        }

        usort($indexes, static fn(int $a, int $b): int => [$rank($routes[$a]), $a] <=> [$rank($routes[$b]), $b]);

        $ordered = [];
        foreach ($indexes as $index) {
            $ordered[$index] = $routes[$index];
        }

        return $ordered;
    }
}
