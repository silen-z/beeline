<?php

declare(strict_types=1);

namespace SilenZ\Beeline\Benchmarks;

use FastRoute\RouteCollector as FastRouteCollector;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\RequestContext;

use function count;
use function FastRoute\cachedDispatcher;

/**
 * One cold request as under PHP-FPM: load the router from its cache file, then match one path.
 * Each rev takes the next path of {@see Fixtures::paths()}.
 */
#[Groups(['load-match'])]
#[BeforeMethods('setUp')]
#[ParamProviders('provideFixtures')]
#[Revs(100)]
#[Iterations(5)]
#[Warmup(1)]
final class LoadMatchBench
{
    private string $fixture = '';

    private string $fastRouteFile = '';

    private string $symfonyFile = '';

    /** @var non-empty-list<string> */
    private array $paths = [''];

    private int $count = 1;

    private int $next = 0;

    /**
     * @param array{fixture: string} $params
     */
    public function setUp(array $params): void
    {
        $this->fixture = $params['fixture'];
        $this->fastRouteFile = Routers::writeCaches($params['fixture']);
        $this->symfonyFile = Routers::symfonyFile($params['fixture']);
        $this->paths = Fixtures::paths($params['fixture']);
        $this->count = count($this->paths);
        $this->next = 0;
    }

    public function benchFlat(): void
    {
        Routers::cachedFlat($this->fixture)->match($this->paths[$this->next++ % $this->count]);
    }

    public function benchFastRoute(): void
    {
        // The definition callback is never invoked while the cache file exists.
        $dispatcher = cachedDispatcher(static function (FastRouteCollector $_collector): void {}, [
            'cacheFile' => $this->fastRouteFile,
        ]);
        $dispatcher->dispatch('GET', $this->paths[$this->next++ % $this->count]);
    }

    public function benchSymfony(): void
    {
        /** @var array<array-key, mixed> $compiled */
        $compiled = require $this->symfonyFile;
        $matcher = new CompiledUrlMatcher($compiled, new RequestContext());
        try {
            $matcher->match($this->paths[$this->next++ % $this->count]);
        } catch (ResourceNotFoundException) {
            // @mago-expect lint:no-empty-catch-clause - a non-matching path is an expected 404
            // outcome for this benchmark, not an error worth logging.
        }
    }

    /**
     * @return iterable<string, array{fixture: string}>
     */
    public function provideFixtures(): iterable
    {
        return Fixtures::provideFixtures();
    }
}
