# Beeline

A segment-tree router for PHP 8.4. Route declarations are compiled into a flat table of integer
node IDs, which is stored as a plain `return [...]` PHP file and matched by one generic loop.

The router only answers *which route matched, and what were the parameters*. It never interprets
the metadata attached to a route, so handlers, HTTP methods and middleware stay the application's
business.

The core is deliberately small: full paths in, metadata out. HTTP methods, groups and middleware
come from `Routes`, a declaration layer built on top of it in the companion
[`silenz/switchyard`](../switchyard/README.md) package (see its
[HTTP routes](../switchyard/README.md#http-routes)).

## Usage

```php
use SilenZ\Beeline\Cache\FileCache;
use SilenZ\Beeline\RouteDefinition;
use SilenZ\Beeline\RouteMatch;
use SilenZ\Beeline\Router;
use SilenZ\Beeline\RouteTable;

$routes = new RouteTable(
    static fn(): array => [
        new RouteDefinition('/', ['handler' => 'home']),
        new RouteDefinition('/api/users/{id}', ['handler' => 'users.show', 'middleware' => ['auth']]),
        new RouteDefinition('/assets/{path+}', ['handler' => 'assets']),
    ],
    cacheKey: 'routes-' . APP_VERSION,
);
$router = new Router($routes, cache: APP_DEBUG ? null : new FileCache(__DIR__ . '/var/cache'));

$result = $router->match('/api/users/42');
if ($result instanceof RouteMatch) {
    $result->route;  // ['handler' => 'users.show', 'middleware' => ['auth']]
    $result->params; // ['id' => '42']
}
```

- **Routes come from a `RouteTable`:** a cache key, the route definitions behind it and optional
  metadata of the table's own, as one unit — a cache key that doesn't change with what the
  definitions produce risks silently serving stale routes, so they live together instead of being
  passed to `Router` as separate arguments. The definitions are a callable — a closure, an
  invokable object, a function or method name — called only when the routes need compiling, or a
  plain array of `RouteDefinition`s used as given. A generator (`yield`, `yield from` to combine
  sources) goes in as the callable producing it, since it can only be iterated once. A
  `RouteDefinition` parses its path when it's created, so a malformed path throws where it's
  declared. Switchyard's `Routes` gives you one too (`$routes->table(...)`, see its
  [HTTP routes](../switchyard/README.md#http-routes)).
- A `RouteTable` also carries an optional `MetadataRegistry` (`registry()`, `null` by default),
  read back the same way from `$router->table()->registry()`. The core router never reads it
  itself — it's opaque cargo, there purely so a declaration layer like Switchyard's `Routes` can
  keep it paired with the table it was built for; see its
  [HTTP routes](../switchyard/README.md#http-routes).
- `match()` takes the path only (no query string) and returns a `RouteMatch` or a `NoMatch`.
  Parameter values are `rawurldecode`d.
- `Router` is a thin entry point over the lower-level pieces, exposing `match()`, `metadata()` and
  `routes()` (the route table's own metadata, and every route's, both from the cache on a hit).
  `new Matcher(Compiler::compile(new RouteTable($routes)))` gives the same thing without any
  caching, for whoever wants to skip `Router` entirely.

Metadata is written into the cache, so it may only contain scalars, `null`, enums and arrays of
those. Anything else is rejected at compile time.

### Caching

Caching works like FastRoute's cached dispatcher:

- **The definitions callable runs only on a cache miss.** It runs the first time the router is used
  and the cache has no entry for the table's key. On a warm request the routes are not declared at
  all; the compiled table comes straight from the cache. The key is read every time, which is why
  it's a plain string rather than something computed.
- **Nothing is invalidated automatically.** Anything that changes which routes get compiled must
  change the cache key: a deploy, or configuration that decides which routes exist. Put an
  application version or a hash of that configuration into it. Different keys are separate cache
  entries.
- **A `null` key, or no `$cache` at all, disables caching.** Routes are then compiled whenever a
  `Router` is first used, which is what you want in development — `RouteTable`'s key defaults to
  `null` for exactly this reason.
- **Any storage works.** `Cache\RouteCache` is a two-method interface (`get(key)`, `set(key,
  compiled)`). `Cache\FileCache` stores each key as a PHP file in a directory, written atomically
  and loaded with `require`, so OPcache serves it from memory. A key made of letters, digits,
  `.`, `_` and `-` is the file name (`routes-v2` => `routes-v2.php`); other keys are made safe and
  get a short hash (`tenant/a` => `tenant_a~1f3c8a2b.php`). Entries written by an incompatible
  router version are ignored and recompiled.
- **A table can cache metadata of its own.** `new RouteTable($definitions, $key, metadata: ...)`
  takes plain data that belongs to the routes as a whole rather than to any one route, or a closure
  producing it, which like the definitions' only runs on a miss. It's compiled and cached with the
  routes; `$router->metadata()` reads it back either way. Matching never returns it.

### Several routes per path and filters

Several routes may share a path, typically one per HTTP method. A *filter* passed to `match()`
decides which of them applies:

```php
new RouteDefinition('/users', ['methods' => ['GET'], 'handler' => 'users.list']),
new RouteDefinition('/users', ['methods' => ['POST'], 'handler' => 'users.create']),

$result = $router->match($path, static fn(RouteMatch $match): bool => in_array($method, $match->route['methods'], true));

if ($result instanceof RouteMatch) {
    // dispatch $result->route with $result->params
} elseif ($result->rejected === []) {
    // 404: no route has this path
} else {
    // 405: the path exists for other methods; build Allow from $result->rejected
}
```

- **Candidates are offered in order.** The filter receives each candidate as a `RouteMatch` (its
  metadata and decoded parameters), in precedence order and, for routes sharing a path, in
  declaration order. The first accepted route wins.
- **A rejected route behaves as if it didn't exist.** Matching continues and may backtrack: with
  `POST /foo/bar` and `GET /foo/{id}`, a `GET /foo/bar` matches the second route.
- **Rejections are reported.** If nothing is accepted, `NoMatch::$rejected` lists the metadata of
  every rejected candidate, which is what a 405 response needs.
- **Without a filter**, the first declared route of the best path wins.

Filters decide whether a route *applies to the request*: HTTP method, host, content type, parameter
format (`{id}` must be numeric), a feature switch. They must not check *who is asking*.
Authentication and permissions belong to middleware after matching, because a rejected route
falls through to other routes (or a 404) instead of producing a 401 or 403. Filters may run several
times per match, so keep them cheap and free of side effects: load any configuration once, before
matching, and let the closure capture it.

## Path syntax

| Segment    | Matches                                                                   |
|------------|---------------------------------------------------------------------------|
| `users`    | exactly that segment                                                      |
| `{id}`     | one non-empty segment                                                     |
| `{path*}`  | the rest of the path, zero or more segments: `/assets`, `/assets/`, `/assets/a/b` |
| `{path+}`  | the rest of the path, which must be non-empty: `/assets/a/b`, not `/assets/` |

- Paths start with `/`.
- Placeholders always cover a whole segment. `/file.{ext}` is rejected.
- A catch-all must be the last segment.
- Matching is case-sensitive.
- Trailing slashes matter: `/foo` and `/foo/` are different routes. Empty segments are only
  allowed as a trailing slash.
- When several routes fit, the order is **static > parameter > catch-all**. If a branch fails
  deeper down, matching backtracks: with `/foo/bar` and `/foo/{id}/baz` declared, `/foo/bar/baz`
  matches the second route.
- An exact route takes precedence over a `{path*}` catch-all hanging off the same node.

### Declaration rules (enforced at compile time)

- Routes may share a path, also with different parameter names (`/foo/{id}` and `/foo/{name}`);
  a filter chooses between them.
- Catch-alls hanging off the same node must all be `{name*}` or all be `{name+}`.

## Architecture

```
RouteDefinitions ──► Compiler ──────► Flattener ──► RouteCache ──► Matcher
 paths + metadata    tree + checks,   tables        e.g. FileCache  static hash lookup, then tree loop + backtracking
                     static table

Router wires these together: on a cache miss it declares, compiles and stores the routes.
```

- **Routes without parameters** are answered from a static table keyed by the full path, a single
  hash lookup.
- **Everything else** goes through the tree, flattened into sparse tables keyed by node id: static
  edges, `{param}` edges, catch-alls and the routes ending at each node, with route ids in
  declaration order. A node only appears in the tables it has something in, so the cache file stays
  close to the size of what actually exists. The full layout is documented on `Compiler`.
- **A route's metadata and parameter names** live in a separate route table, so the traversal
  loop only deals with integers and segment strings.

## Development

There's no PHP on the host by assumption — use the Docker setup in `docker/`:

```bash
docker compose up -d
docker compose exec php composer install
docker compose exec php composer qa
```

`composer qa` runs `mago format --check`, `mago lint`, `mago analyze` and then the tests
(`composer test`). If Docker isn't available, any PHP ≥8.4 CLI binary works the same way.

```bash
composer bench
```

The benchmarks (PHPBench) compare this router with FastRoute in five groups:

- `match`: steady-state lookups, one benchmark per interesting case
- `match-mixed`: steady-state lookups cycling through every route of a fixture plus 404s
- `load-match`: loading the cache file and matching one path, as on a cold PHP-FPM request
- `load`: turning an existing cache file into a matcher
- `compile`: building from declarations

Run one group with `vendor/bin/phpbench run --group=match --report=aggregate`. The fixtures are in
`benchmarks/fixtures/`, and `tests/BenchmarkFixturesTest.php` checks that both routers return the
same result for every benchmark request.

On Windows, `phpbench.json` sets `runner.remote_script_path` to a relative directory. Without it,
PHPBench fails when the temp directory path contains non-ASCII characters.
