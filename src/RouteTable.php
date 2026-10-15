<?php

declare(strict_types=1);

namespace SilenZ\Beeline;

/**
 * Where {@see Router} gets its routes from: a cache key, an optional {@see MetadataRegistry}, and a
 * callable that declares the route definitions and the table's own metadata — as one unit.
 *
 *     new RouteTable(static function (array &$definitions, mixed &$metadata): void {
 *         $definitions = [new RouteDefinition('/', 'home')];
 *     }, 'routes-' . APP_VERSION);
 *
 * Unlike earlier shapes of this class, `RouteTable` itself holds no declared state and does no
 * memoizing: `$collect` is a plain delegate, called exactly as many times as {@see collect()} is
 * called. Calling it more than once per request (re-declaring, possibly re-populating a
 * {@see MetadataRegistry} a second time) is a correctness question for whoever calls {@see collect()}
 * — {@see Router} — not something this class protects against on its own; see {@see Router::load()}.
 * {@see definitions()}/{@see metadata()} exist for a caller that only wants one side and doesn't care
 * about calling $collect again to get it (e.g. tooling that reads routes but never compiles) — they're
 * not the router's own hot path.
 *
 * The key lives on the same object, instead of being passed to `Router` as a separate argument, so
 * the two can't drift apart the way two independent values could: whatever decides the key is right
 * there next to whatever decides the definitions — the same reason {@see registry()} lives here too,
 * rather than traveling separately alongside a `Router`.
 *
 * Nothing is invalidated automatically, so anything that changes which routes get compiled (a deploy,
 * configuration deciding which routes exist) must change the cache key, e.g. by including an
 * application version or a configuration hash in it.
 */
final class RouteTable
{
    /** @var callable(array<int, RouteDefinition> &$definitions, mixed &$metadata): void */
    private readonly mixed $collect;

    /**
     * @param callable(array<int, RouteDefinition> &$definitions, mixed &$metadata): void $collect
     *     fills in both outputs; called exactly once per {@see collect()} call, with no memoizing of
     *     its own — see the class docs.
     * @param ?string $cacheKey identifies these routes in the cache; `null` (the default) still caches,
     *     under whatever the given `RouteCache` treats as its own default for a `null` key — only
     *     `Router` being given no cache at all compiles on every request instead, e.g. in development.
     *     A plain value, since it's read on every request
     * @param ?MetadataRegistry $registry opaque to this class and to the core router, neither of which
     *     ever reads it themselves — {@see Matcher}/{@see Router} only consult it through
     *     {@see registry()}, and only when given one at all. `null` (the default) for a table whose
     *     metadata is already fully cache-safe ({@see Http\LazyRoutes} never needs one, since it never
     *     declares anything that couldn't survive a cache round trip); a declaration layer such as
     *     `Http\Routes` that allows real instances gives one instead, so its routes' and its own
     *     metadata can hold them freely — registering through it, if `$collect` wants to, is
     *     `$collect`'s job, not this class's.
     */
    public function __construct(
        callable $collect,
        private readonly ?string $cacheKey = null,
        private readonly ?MetadataRegistry $registry = null,
    ) {
        $this->collect = $collect;
    }

    public function cacheKey(): ?string
    {
        return $this->cacheKey;
    }

    /**
     * The table's {@see MetadataRegistry}, if it has one — a plain getter, unlike earlier shapes of
     * this class: populating it is {@see Router}'s job now (see {@see Router::load()}), not a side
     * effect of reading this.
     */
    public function registry(): ?MetadataRegistry
    {
        return $this->registry;
    }

    /**
     * Declares the routes and the table's own metadata in one pass, by calling `$collect` — given at
     * construction — exactly once. Calling this more than once per request runs `$collect` again in
     * full, including any side effect it has (e.g. repopulating a {@see MetadataRegistry}); whether
     * that's safe is the caller's question, not this method's.
     *
     * @param array<int, RouteDefinition> &$definitions
     */
    public function collect(array &$definitions, mixed &$metadata): void
    {
        ($this->collect)($definitions, $metadata);
    }

    /**
     * Convenience for a caller that only wants the definitions and doesn't care that this runs
     * `$collect` in full to get them — e.g. tooling that reads routes but never compiles or matches.
     * Not {@see Router}'s own path: it calls {@see collect()} directly, once, to get both outputs from
     * a single declare.
     *
     * @return array<int, RouteDefinition>
     */
    public function definitions(): array
    {
        $definitions = [];
        $metadata = null;
        $this->collect($definitions, $metadata);

        return $definitions;
    }

    /**
     * Convenience for a caller that only wants the table's own metadata; see {@see definitions()}.
     */
    public function metadata(): mixed
    {
        $definitions = [];
        $metadata = null;
        $this->collect($definitions, $metadata);

        return $metadata;
    }
}
