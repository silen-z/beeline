<?php

declare(strict_types=1);

namespace SilenZ\Beeline\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use SilenZ\Beeline\Cache\FileCache;
use SilenZ\Beeline\Compiler;
use SilenZ\Beeline\RouteDefinition;
use SilenZ\Beeline\RouteMatch;
use SilenZ\Beeline\Router;
use SilenZ\Beeline\RouteTable;
use SilenZ\Beeline\Tests\Fixtures\Method;

use function basename;
use function dirname;
use function file_put_contents;
use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class FileCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/segmatch-' . uniqid() . '/cache';
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $files = glob($this->directory . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        rmdir(dirname($this->directory));
    }

    public function testStoredRoutesAreReadBackUnchanged(): void
    {
        $routes = [];
        $routes[] = new RouteDefinition('/api/users/{id}', [
            'methods' => [Method::Get, Method::Put],
            'handler' => ['UserController', 'show'],
            'weight' => 1.5,
            'public' => false,
            'extra' => null,
            "quote'd" => "it's",
        ]);
        $routes[] = new RouteDefinition('/api/123', 'numeric segment');
        $routes[] = new RouteDefinition('/assets/{path*}', 'assets');
        $compiled = Compiler::compile(new RouteTable($routes, metadata: [
            'middleware' => ['cors', Method::Get],
            'weight' => 0.5,
        ]));

        $cache = new FileCache($this->directory);
        $cache->set('routes', $compiled);

        static::assertSame($compiled, $cache->get('routes'));
    }

    public function testMissingKeyReturnsNull(): void
    {
        static::assertNull(new FileCache($this->directory)->get('routes'));
    }

    public function testANullKeyIsStoredAndReadBackUnchanged(): void
    {
        $compiled = Compiler::compile(new RouteTable([new RouteDefinition('/a', 'a')]));

        $cache = new FileCache($this->directory);
        $cache->set(null, $compiled);

        static::assertSame($compiled, $cache->get(null));
    }

    public function testFileThatDoesNotReturnAnArrayIsIgnored(): void
    {
        $cache = new FileCache($this->directory);
        $cache->set('routes', Compiler::compile(new RouteTable([])));
        file_put_contents($cache->file('routes'), data: '<?php return 42;');

        static::assertNull($cache->get('routes'));
    }

    public function testEachKeyHasItsOwnFile(): void
    {
        $cache = new FileCache($this->directory . '/');

        static::assertMatchesRegularExpression('/^routecache_[0-9a-f]{8}\.php$/', basename($cache->file('routes-v2')));
        static::assertSame($this->directory, dirname($cache->file('routes-v2')));
        static::assertNotSame($cache->file('routes-v1'), $cache->file('routes-v2'));
    }

    public function testKeysWithAnyCharactersDoNotCollide(): void
    {
        $cache = new FileCache($this->directory);

        static::assertMatchesRegularExpression(
            '/^routecache_[0-9a-f]{8}\.php$/',
            basename($cache->file('tenant/a:routes')),
        );
        static::assertNotSame($cache->file('tenant/a:routes'), $cache->file('tenant:a/routes'));
        static::assertNotSame($cache->file(''), $cache->file('tenant/a:routes'));
    }

    public function testANullKeyUsesItsOwnSharedFileSeparateFromAnyStringKey(): void
    {
        $cache = new FileCache($this->directory);

        static::assertSame('routecache.php', basename($cache->file(null)));
        static::assertNotSame($cache->file(null), $cache->file(''));
        static::assertSame($cache->file(null), $cache->file(null));
    }

    public function testRouterUsesTheFileCache(): void
    {
        $cache = new FileCache($this->directory);
        new Router(new RouteTable(static fn(): array => [new RouteDefinition('/a', 'a')], 'app'), $cache)->match('/a');

        $router = new Router(
            new RouteTable(static fn() => throw new LogicException('should not compile'), 'app'),
            $cache,
        );

        $result = $router->match('/a');

        static::assertFileExists($cache->file('app'));
        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame('a', $result->route);
    }
}
