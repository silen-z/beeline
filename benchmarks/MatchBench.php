<?php

declare(strict_types=1);

namespace SilenZ\Beeline\Benchmarks;

use FastRoute\Dispatcher;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use SilenZ\Beeline\Matcher;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;

/**
 * Steady-state matching: both routers are built once in setUp(), only the lookup is measured.
 *
 * FastRoute's dispatch() includes its HTTP method lookup; this router leaves method handling to the
 * caller, so the comparison slightly favours it by one hash lookup.
 */
#[Groups(['match'])]
#[BeforeMethods('setUp')]
#[ParamProviders('provideRequests')]
#[Revs(20_000)]
#[Iterations(10)]
#[Warmup(2)]
final class MatchBench
{
    private Matcher $flat;

    private Dispatcher $fastRoute;

    private CompiledUrlMatcher $symfony;

    /**
     * @param array{fixture: string, case: string, path: string} $params
     */
    public function setUp(array $params): void
    {
        $routes = Fixtures::get($params['fixture'])['routes'];
        $this->flat = Routers::flat($routes);
        $this->fastRoute = Routers::fastRoute($routes);
        $this->symfony = Routers::symfony($routes);
    }

    /**
     * @param array{fixture: string, case: string, path: string} $params
     */
    public function benchFlat(array $params): void
    {
        $this->flat->match($params['path']);
    }

    /**
     * @param array{fixture: string, case: string, path: string} $params
     */
    public function benchFastRoute(array $params): void
    {
        $this->fastRoute->dispatch('GET', $params['path']);
    }

    /**
     * @param array{fixture: string, case: string, path: string} $params
     */
    public function benchSymfony(array $params): void
    {
        try {
            $this->symfony->match($params['path']);
        } catch (ResourceNotFoundException) {
            // @mago-expect lint:no-empty-catch-clause - a non-matching path is an expected 404
            // outcome for this benchmark, not an error worth logging.
        }
    }

    /**
     * @return iterable<string, array{fixture: string, case: string, path: string}>
     */
    public function provideRequests(): iterable
    {
        return Fixtures::provideRequests();
    }
}
