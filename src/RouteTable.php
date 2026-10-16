<?php

declare(strict_types=1);

namespace SilenZ\Beeline;

/**
 * Where {@see Router} gets its routes from: a cache key, an optional {@see MetadataRegistry}, and
 * {@see collect()}, which declares the route definitions and the table's own metadata — as one unit.
 *
 *     final class HomeTable extends RouteTable
 *     {
 *         public function __construct(?string $cacheKey = null)
 *         {
 *             $this->cacheKey = $cacheKey;
 *         }
 *
 *         protected function collect(array &$definitions): mixed
 *         {
 *             $definitions = [new RouteDefinition('/', 'home')];
 *
 *             return null;
 *         }
 *     }
 *
 *     new HomeTable('routes-' . APP_VERSION);
 *
 * This class declares no constructor of its own — a subclass is free to shape its own however it
 * likes (promoted properties, defaults, whatever `collect()` and a real declaration API need), assigning
 * {@see $cacheKey} itself, whenever it actually knows it, instead of being forced through a base
 * constructor that would otherwise dictate that shape. A caller that just wants to hand over a closure
 * instead of declaring a reusable class — e.g. a one-off table in a test or a benchmark — declares its
 * own small concrete subclass for that, the same way `Http\Routes`/`Http\LazyRoutes` do; this class has
 * no closure-wrapping convenience of its own to keep that test/benchmark-only concern out of it.
 *
 * `collect()` itself is `protected`: it runs at most once per instance, the first time either
 * {@see definitions()} or {@see metadata()} is actually called — this class memoizes both, so a caller
 * (e.g. {@see Compiler::compile()}, {@see Router}) reaches them through those two public methods, never
 * `collect()` directly, and can call either of them as many times as it likes without re-declaring. A
 * side effect `collect()` has (e.g. repopulating a {@see MetadataRegistry}) still only runs that once
 * per instance, so a caller after a fresh repopulation each request needs a fresh `RouteTable` instance
 * each request too — reusing one across requests (e.g. in a long-running worker) keeps serving its
 * first declare forever.
 *
 * The key lives on the same object, instead of being passed to `Router` as a separate argument, so
 * the two can't drift apart the way two independent values could: whatever decides the key is right
 * there next to whatever decides the definitions.
 *
 * Nothing is invalidated automatically, so anything that changes which routes get compiled (a deploy,
 * configuration deciding which routes exist) must change the cache key, e.g. by including an
 * application version or a configuration hash in it.
 */
abstract class RouteTable
{
    /**
     * Identifies these routes in the cache; `null` (the never-assigned default) still caches, under
     * whatever the given `RouteCache` treats as its own default for a `null` key — only `Router` being
     * given no cache at all compiles on every request instead, e.g. in development.
     *
     * Protected, not private: a subclass assigns it directly, typically only once (e.g.
     * `Http\Routes::table()`, the moment a declared tree becomes a table) — there's no setter method to
     * go through instead, since {@see cacheKey()} itself is the only part of this meant to be called
     * from outside the class.
     */
    protected ?string $cacheKey = null;

    /**
     * Final: every `RouteTable` shares this one cache key ({@see $cacheKey}) and reads it back the same
     * way — a subclass isn't meant to reimplement this, only to assign the property.
     */
    final public function cacheKey(): ?string
    {
        return $this->cacheKey;
    }

    /**
     * The table's {@see MetadataRegistry}, if it has one. `null` by default — opaque to this class and
     * to the core router either way, neither of which ever reads it themselves; {@see Matcher}/
     * {@see Router} only consult it through this method, and only when given one at all. A table whose
     * metadata is already fully cache-safe (e.g. {@see Http\LazyRoutes}, which never declares anything
     * that couldn't survive a cache round trip) has no reason to override this; a declaration layer
     * that allows real instances, like {@see Http\Routes}, overrides it with its own instead, so its
     * routes' and its own metadata can hold them freely — registering through it, if {@see collect()}
     * wants to, is that subclass's job, not this one's.
     */
    public function registry(): ?MetadataRegistry
    {
        return null;
    }

    private bool $collected = false;

    /** @var array<array-key, mixed> whatever {@see collect()} actually produced — not verified here */
    private array $definitions = [];

    private mixed $metadata = null;

    /**
     * Fills in the route definitions and returns the table's own metadata, in one pass.
     * {@see ensureCollected()} runs this at most once per instance, so a side effect it has (e.g.
     * repopulating a {@see MetadataRegistry}) runs that same one time, no matter how often
     * {@see definitions()}/{@see metadata()} are called.
     *
     * @param array<array-key, mixed> &$definitions always empty when this runs; what it should hold
     *     afterward is only documented as `array<int, RouteDefinition>`, not a guarantee — an
     *     implementation can leave anything here, so what actually comes out may be anything — see
     *     {@see Compiler::compile()}'s own check.
     * @param-out array<array-key, mixed> $definitions
     */
    abstract protected function collect(array &$definitions): mixed;

    /**
     * The routes, declaring them first if nothing has yet — see {@see collect()}. Not {@see Router}'s
     * own path: it reaches these through {@see Compiler::compile()}/{@see Matcher} instead, already
     * compiled, from the cache on a hit.
     *
     * @return array<int, RouteDefinition>
     */
    public function definitions(): array
    {
        $this->ensureCollected();

        /** @var array<int, RouteDefinition> trusted, not verified — same as the rest of this class; {@see Compiler::compile()} is what actually checks. */
        return $this->definitions;
    }

    /**
     * The table's own metadata, declaring the routes first if nothing has yet; see {@see definitions()}.
     */
    public function metadata(): mixed
    {
        $this->ensureCollected();

        return $this->metadata;
    }

    /**
     * Runs {@see collect()} the first time either {@see definitions()} or {@see metadata()} needs it,
     * never again after — {@see $collected} is its own flag rather than, say, checking whether
     * {@see $metadata} is still `null`, since `null` is also a perfectly valid *result* of collecting
     * (a table that never assigns its own metadata) and would otherwise look indistinguishable from
     * never having collected at all.
     */
    private function ensureCollected(): void
    {
        if ($this->collected) {
            return;
        }

        $this->metadata = $this->collect($this->definitions);
        $this->collected = true;
    }
}
