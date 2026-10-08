<?php

declare(strict_types=1);

namespace League\Flysystem\InMemory\Tests;

use League\Flysystem\Config;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\InMemory\StaticInMemoryAdapterRegistry;
use PHPUnit\Framework\TestCase;

class StaticInMemoryAdapterRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        StaticInMemoryAdapterRegistry::deleteAllFilesystems();
    }

    protected function tearDown(): void
    {
        StaticInMemoryAdapterRegistry::deleteAllFilesystems();
    }

    public function testDefaultGetReturnsTheSameInstance(): void
    {
        $first = StaticInMemoryAdapterRegistry::get();
        $second = StaticInMemoryAdapterRegistry::get();

        self::assertSame($first, $second);

        $first->write('foo.txt', 'foo', new Config(['timestamp' => 1]));
        self::assertTrue($second->fileExists('foo.txt'));
    }

    public function testNamedRegistriesAreIsolated(): void
    {
        $default = StaticInMemoryAdapterRegistry::get();
        $second = StaticInMemoryAdapterRegistry::get('second');

        self::assertNotSame($default, $second);

        $default->write('foo.txt', 'foo', new Config(['timestamp' => 1]));
        $second->write('bar.txt', 'bar', new Config(['timestamp' => 2]));

        self::assertTrue($default->fileExists('foo.txt'));
        self::assertFalse($default->fileExists('bar.txt'));
        self::assertTrue($second->fileExists('bar.txt'));
        self::assertFalse($second->fileExists('foo.txt'));
    }

    public function testFilesPersistUntilDeleteAll(): void
    {
        $first = StaticInMemoryAdapterRegistry::get();
        $first->write('foo.txt', 'foo', new Config(['timestamp' => 1]));

        $second = StaticInMemoryAdapterRegistry::get('second');
        $second->write('bar.txt', 'bar', new Config(['timestamp' => 2]));

        $again = StaticInMemoryAdapterRegistry::get();
        self::assertSame($first, $again);
        self::assertTrue($again->fileExists('foo.txt'));

        StaticInMemoryAdapterRegistry::deleteAllFilesystems();

        $fresh = StaticInMemoryAdapterRegistry::get();
        self::assertNotSame($first, $fresh);
        self::assertFalse($fresh->fileExists('foo.txt'));
        self::assertFalse(StaticInMemoryAdapterRegistry::get('second')->fileExists('bar.txt'));
    }
}
