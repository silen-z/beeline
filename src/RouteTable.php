<?php

declare(strict_types=1);

namespace SilenZ\Beeline;

use Closure;

use function is_array;
use function is_callable;
use function iterator_to_array;

/**
 * Where {@see Router} gets its routes from: a cache key, the route definitions behind it, the
 * table's own metadata and an optional {@see MetadataRegistry}, as one unit.
 *
 *     new RouteTable(static fn(): array => [new RouteDefinition('/', 'home')], 'routes-' . APP_VERSION);
 *
 * Like FastRoute's cached dispatcher, the definitions only need producing when the cache has no entry
 * for {@see cacheKey()}: given as a callable, they're only declared then, on first use — see
 * {@see definitions()}. The key lives on the same object, instead of being passed to `Router` as a
 * separate argument, so the two can't drift apart the way two independent values could: whatever
 * decides the key is right there next to whatever decides the definitions — the same reason
 * {@see registry()} lives here too, rather than traveling separately alongside a `Router`.
 *
 * Nothing is invalidated automatically, so anything that changes which routes get compiled (a deploy,
 * configuration deciding which routes exist) must change the cache key, e.g. by including an
 * application version or a configuration hash in it.
 */
final class RouteTable
{
    /** @var (Closure(): iterable<int, RouteDefinition>)|iterable<int, RouteDefinition> */
    private readonly Closure|iterable $definitions;

    private readonly mixed $metadataSource;

    /** @var ?array<int, RouteDefinition> */
    private ?array $resolvedDefinitions = null;

    private bool $metadataResolved = false;

    private mixed $resolvedMetadata = null;

    /**
     * @param iterable<int, RouteDefinition>|callable(): iterable<int, RouteDefinition> $definitions
     *     the routes, in declaration order: a callable (a closure, an invokable object, a function or
     *     method name) is only called the first time they're needed — see {@see definitions()}. Give
     *     a generator as a callable producing it, since one can only be iterated once; this materializes
     *     it into an array the first time, so later calls can still read it.
     * @param ?string $cacheKey identifies these routes in the cache; `null` (the default) still caches,
     *     under whatever the given `RouteCache` treats as its own default for a `null` key — only
     *     `Router` being given no cache at all compiles on every request instead, e.g. in development.
     *     A plain value, since it's read on every request
     * @param mixed $metadata metadata of these routes as a whole rather than of any one route, e.g.
     *     what applies to every request whether a route matches or not: plain data like a route's own,
     *     cached with the routes, or a closure producing it, called like the definitions' only the
     *     first time it's needed. Only a `Closure` counts as lazy here, not any callable: plain
     *     metadata like `'trim'` or `['Foo', 'bar']` would pass for one. Read back with
     *     {@see metadata()}, or, from the cache, with {@see Matcher::metadata()} via
     *     {@see Router::matcher()}
     * @param ?MetadataRegistry $registry opaque to the core router, which never reads it itself —
     *     {@see Matcher}/{@see Router} only consult it through {@see registry()}, and only when given
     *     one at all. `null` (the default) for a table whose metadata is already fully cache-safe
     *     ({@see Http\LazyRoutes} never needs one, since it never declares anything that couldn't
     *     survive a cache round trip); a declaration layer such as `Http\Routes` that allows real
     *     instances gives one instead, so its routes' and its own metadata can hold them freely.
     */
    public function __construct(
        iterable|callable $definitions,
        private readonly ?string $cacheKey = null,
        mixed $metadata = null,
        private readonly ?MetadataRegistry $registry = null,
    ) {
        // A list of RouteDefinitions is never callable, so a callable is always the lazy form.
        $this->definitions = is_callable($definitions) ? $definitions(...) : $definitions;
        $this->metadataSource = $metadata;
    }

    public function cacheKey(): ?string
    {
        return $this->cacheKey;
    }

    /**
     * The table's {@see MetadataRegistry}, if it has one — ensuring it's populated first: declaring
     * (if not already done this request, see {@see definitions()}/{@see metadata()}) is what populates
     * it, as a side effect of building the full metadata {@see Http\Route}/{@see Http\Routes} hand it.
     */
    public function registry(): ?MetadataRegistry
    {
        if ($this->registry !== null) {
            // Called for the side effect of populating the registry; the result is read again,
            // memoized, by whoever actually needs it (e.g. Compiler::compile()).
            $this->definitions();
            $this->metadata();
        }

        return $this->registry;
    }

    /**
     * The routes themselves, declared the first time they're needed and memoized after that, for the
     * lifetime of this table — so declaring with a side effect (such as populating a
     * {@see MetadataRegistry}) only ever happens once per request, however many times this is called.
     *
     * @return iterable<int, RouteDefinition>
     */
    public function definitions(): iterable
    {
        if ($this->resolvedDefinitions === null) {
            $produced = $this->definitions instanceof Closure ? ($this->definitions)() : $this->definitions;
            $this->resolvedDefinitions = is_array($produced)
                ? $produced
                : iterator_to_array($produced, preserve_keys: false);
        }

        return $this->resolvedDefinitions;
    }

    /**
     * The table's own metadata, declared the first time it's needed and memoized after that, same as
     * {@see definitions()}; `null` by default.
     */
    public function metadata(): mixed
    {
        if ($this->metadataResolved) {
            return $this->resolvedMetadata;
        }

        $this->metadataResolved = true;

        return $this->resolvedMetadata = $this->resolveMetadataSource();
    }

    private function resolveMetadataSource(): mixed
    {
        return $this->metadataSource instanceof Closure ? ($this->metadataSource)() : $this->metadataSource;
    }
}
