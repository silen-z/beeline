<?php

declare(strict_types=1);

namespace SilenZ\Beeline;

use function count;

/**
 * Holds the live metadata a {@see RouteTable} declares — every route's own, plus the table's own, in
 * one index space — so a compiled cache entry only ever has to carry a plain id, never a route's real
 * handler, middleware or filter instances.
 *
 * Declaring is what populates it: {@see Http\Route}/{@see Http\Routes} hand their fully assembled
 * metadata to {@see register()} as one unit each, as soon as it's known (group-inherited middleware
 * and tags included) — not wrapped piece by piece the way an earlier design did it. That's why it's
 * never cached itself: it's rebuilt fresh by re-declaring on every request, same as the routes it
 * describes, so metadata may freely hold real instances or closures that could never survive a round
 * trip through a cache file.
 *
 * {@see Matcher} reads it back through {@see get()} when one is given — entirely optional, since
 * {@see Http\LazyRoutes} never declares anything that needs to survive as a live instance, so a
 * `Router` built from it has nothing to resolve and needs no registry at all.
 */
final class MetadataRegistry
{
    /** @var list<mixed> */
    private array $values = [];

    /**
     * Adds one entry — a route's full metadata, or the table's own — returning the id {@see get()}
     * resolves it back from, always in the order it was added.
     */
    public function register(mixed $metadata): int
    {
        $this->values[] = $metadata;

        return count($this->values) - 1;
    }

    public function get(int $id): mixed
    {
        return $this->values[$id];
    }
}
