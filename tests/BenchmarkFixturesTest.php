<?php

declare(strict_types=1);

namespace SilenZ\Beeline\Tests;

use FastRoute\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SilenZ\Beeline\Benchmarks\Fixtures;
use SilenZ\Beeline\Benchmarks\Routers;
use SilenZ\Beeline\Matcher;
use SilenZ\Beeline\NoMatch;
use SilenZ\Beeline\RouteMatch;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;

use function array_diff_key;
use function array_filter;
use function array_key_exists;
use function ksort;

/**
 * Benchmarks are only meaningful if every router does the same work, so every benchmark request
 * must produce the same result in all of them.
 */
final class BenchmarkFixturesTest extends TestCase
{
    /** @var array<string, array{Matcher, Dispatcher, CompiledUrlMatcher}> */
    private static array $routers = [];

    /**
     * Building the large fixtures is slow, so every router is built once per fixture.
     *
     * @return array{Matcher, Dispatcher, CompiledUrlMatcher}
     */
    private static function routers(string $fixture): array
    {
        if (!array_key_exists($fixture, self::$routers)) {
            $routes = Fixtures::get($fixture)['routes'];
            self::$routers[$fixture] = [Routers::flat($routes), Routers::fastRoute($routes), Routers::symfony($routes)];
        }

        return self::$routers[$fixture];
    }

    /**
     * @return ?array<array-key, mixed>
     */
    private static function symfonyMatch(CompiledUrlMatcher $symfony, string $path): ?array
    {
        try {
            return $symfony->match($path);
        } catch (ResourceNotFoundException) {
            return null;
        }
    }

    /**
     * @return iterable<string, array{fixture: string, case: string, path: string}>
     */
    public static function requestProvider(): iterable
    {
        return Fixtures::provideRequests();
    }

    #[DataProvider('requestProvider')]
    public function testRoutersAgree(string $fixture, string $case, string $path): void
    {
        [$flatRouter, $fastRouteRouter, $symfonyRouter] = self::routers($fixture);
        $flat = $flatRouter->match($path);
        $fastRoute = $fastRouteRouter->dispatch('GET', $path);
        $symfony = self::symfonyMatch($symfonyRouter, $path);

        if ($case === 'not-found' || $case === 'backtrack-miss') {
            static::assertInstanceOf(NoMatch::class, $flat);
            static::assertSame(Dispatcher::NOT_FOUND, $fastRoute[0]);
            static::assertNull($symfony, 'Symfony found a match');

            return;
        }

        static::assertInstanceOf(RouteMatch::class, $flat, 'flat router found no match');
        static::assertSame(Dispatcher::FOUND, $fastRoute[0], 'FastRoute found no match');
        static::assertNotNull($symfony, 'Symfony found no match');
        static::assertSame($fastRoute[1], $flat->route);
        // $flat->route is the route's metadata, declared mixed by RouteMatch; these fixtures always
        // give it the int index Routers::flatRoutes() registered.
        /** @var int $route */
        $route = $flat->route;
        static::assertSame('r' . $route, $symfony['_route']);
        // FastRoute omits an optional catch-all or optional segment that matched nothing; this
        // router reports '' for the former and null for the latter.
        /** @var array<array-key, mixed> $fastRouteParams */
        $fastRouteParams = $fastRoute[2];
        static::assertSame(self::present($fastRouteParams), self::present($flat->params));
        static::assertSame(self::present($flat->params), self::present(array_diff_key($symfony, ['_route' => true])));
    }

    /**
     * The present (non-empty, non-null) parameters, by name: param order is incidental (Symfony, for
     * one, orders a route's defaults before its matched segments), so only the set of name => value
     * pairs is compared.
     *
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    private static function present(array $params): array
    {
        $present = array_filter($params, static fn(mixed $v): bool => $v !== '' && $v !== null);
        ksort($present);

        return $present;
    }

    /**
     * @return iterable<string, array{fixture: string, path: string}>
     */
    public static function mixedPathProvider(): iterable
    {
        return Fixtures::provideMixedPaths();
    }

    #[DataProvider('mixedPathProvider')]
    public function testRoutersAgreeOnMixedPaths(string $fixture, string $path): void
    {
        [$flatRouter, $fastRouteRouter, $symfonyRouter] = self::routers($fixture);
        $flat = $flatRouter->match($path);
        $fastRoute = $fastRouteRouter->dispatch('GET', $path);
        $symfony = self::symfonyMatch($symfonyRouter, $path);

        if ($flat instanceof NoMatch) {
            static::assertSame(Dispatcher::NOT_FOUND, $fastRoute[0]);
            static::assertNull($symfony, 'Symfony found a match');

            return;
        }

        static::assertSame(Dispatcher::FOUND, $fastRoute[0], 'FastRoute found no match');
        static::assertNotNull($symfony, 'Symfony found no match');
        static::assertSame($fastRoute[1], $flat->route);
        // $flat->route is the route's metadata, declared mixed by RouteMatch; these fixtures always
        // give it the int index Routers::flatRoutes() registered.
        /** @var int $route */
        $route = $flat->route;
        static::assertSame('r' . $route, $symfony['_route']);
        /** @var array<array-key, mixed> $fastRouteParams */
        $fastRouteParams = $fastRoute[2];
        static::assertSame(self::present($fastRouteParams), self::present($flat->params));
        static::assertSame(self::present($flat->params), self::present(array_diff_key($symfony, ['_route' => true])));
    }

    public function testCatchAllPatternTranslation(): void
    {
        static::assertSame('/assets[/{path:.*}]', Routers::toFastRoutePattern('/assets/{path*}'));
        static::assertSame('/assets/{path:.+}', Routers::toFastRoutePattern('/assets/{path+}'));
        static::assertSame('/assets[/{path}]', Routers::toFastRoutePattern('/assets/{path?}'));
        static::assertSame('/users/{id}', Routers::toFastRoutePattern('/users/{id}'));
    }
}
