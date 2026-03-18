<?php

declare(strict_types=1);

namespace Tapbuy\DataScrubber\Tests;

use PHPUnit\Framework\TestCase;
use Tapbuy\DataScrubber\Keys;

class KeysTest extends TestCase
{
    // ── URL validation ──────────────────────────────────────────────────

    public function testRejectsEmptyUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createKeysWithCache('');
    }

    public function testRejectsUrlWithoutScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createKeysWithCache('example.com/keys');
    }

    public function testRejectsFtpScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createKeysWithCache('ftp://example.com/keys');
    }

    public function testRejectsFileScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createKeysWithCache('file:///etc/passwd');
    }

    public function testRejectsUrlWithoutHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createKeysWithCache('https:///path');
    }

    public function testAcceptsHttpsUrl(): void
    {
        $keys = $this->createKeysWithCache('https://example.com/keys', ['email']);
        $this->assertInstanceOf(Keys::class, $keys);
    }

    public function testAcceptsHttpUrl(): void
    {
        $keys = $this->createKeysWithCache('http://example.com/keys', ['email']);
        $this->assertInstanceOf(Keys::class, $keys);
    }

    // ── getKeys ─────────────────────────────────────────────────────────

    public function testGetKeysReturnsLoadedCacheData(): void
    {
        $expected = ['email', 'phone', 'first_name'];
        $keys = $this->createKeysWithCache('https://example.com/keys', $expected);

        $this->assertSame($expected, $keys->getKeys());
    }

    public function testGetKeysReturnsEmptyArrayWhenCacheContainsEmptyArray(): void
    {
        $keys = $this->createKeysWithCache('https://example.com/keys', []);

        $this->assertSame([], $keys->getKeys());
    }

    // ── loadFromCache behaviour ─────────────────────────────────────────

    public function testLoadFromCacheFallsBackToFetchKeysWhenFileIsMissing(): void
    {
        $mock = $this->getMockBuilder(Keys::class)
            ->setConstructorArgs(['https://example.com/keys'])
            ->onlyMethods(['fetchKeys'])
            ->getMock();

        $tmpFile = sys_get_temp_dir() . '/keys_test_missing_' . uniqid() . '.json';
        // Ensure file doesn't exist
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        $reflection = new \ReflectionClass(Keys::class);

        $fileProp = $reflection->getProperty('file');
        $fileProp->setValue($mock, $tmpFile);

        $mock->expects($this->once())->method('fetchKeys');

        $loadFromCache = $reflection->getMethod('loadFromCache');
        $loadFromCache->invoke($mock);
    }

    public function testLoadFromCacheFallsBackToFetchKeysWhenFileIsEmpty(): void
    {
        $mock = $this->getMockBuilder(Keys::class)
            ->setConstructorArgs(['https://example.com/keys'])
            ->onlyMethods(['fetchKeys'])
            ->getMock();

        $tmpFile = tempnam(sys_get_temp_dir(), 'keys_test_');
        file_put_contents($tmpFile, '');

        $reflection = new \ReflectionClass(Keys::class);

        $fileProp = $reflection->getProperty('file');
        $fileProp->setValue($mock, $tmpFile);

        $mock->expects($this->once())->method('fetchKeys');

        $loadFromCache = $reflection->getMethod('loadFromCache');
        $loadFromCache->invoke($mock);

        @unlink($tmpFile);
    }

    public function testLoadFromCacheFallsBackToFetchKeysWhenJsonIsInvalid(): void
    {
        $mock = $this->getMockBuilder(Keys::class)
            ->setConstructorArgs(['https://example.com/keys'])
            ->onlyMethods(['fetchKeys'])
            ->getMock();

        $tmpFile = tempnam(sys_get_temp_dir(), 'keys_test_');
        file_put_contents($tmpFile, '{not valid json');

        $reflection = new \ReflectionClass(Keys::class);

        $fileProp = $reflection->getProperty('file');
        $fileProp->setValue($mock, $tmpFile);

        $mock->expects($this->once())->method('fetchKeys');

        $loadFromCache = $reflection->getMethod('loadFromCache');
        $loadFromCache->invoke($mock);

        @unlink($tmpFile);
    }

    public function testLoadFromCacheDoesNotCallFetchKeysWhenCacheIsValid(): void
    {
        $mock = $this->getMockBuilder(Keys::class)
            ->setConstructorArgs(['https://example.com/keys'])
            ->onlyMethods(['fetchKeys'])
            ->getMock();

        $expectedKeys = ['email', 'phone'];
        $tmpFile = tempnam(sys_get_temp_dir(), 'keys_test_');
        file_put_contents($tmpFile, json_encode($expectedKeys));

        $reflection = new \ReflectionClass(Keys::class);

        $fileProp = $reflection->getProperty('file');
        $fileProp->setValue($mock, $tmpFile);

        $mock->expects($this->never())->method('fetchKeys');

        $loadFromCache = $reflection->getMethod('loadFromCache');
        $loadFromCache->invoke($mock);

        $this->assertSame($expectedKeys, $mock->getKeys());

        @unlink($tmpFile);
    }

    // ── Helper ──────────────────────────────────────────────────────────

    /**
     * Create a Keys instance with a pre-populated temp cache file so the
     * constructor never hits the network.
     */
    private function createKeysWithCache(string $url, array $cacheData = ['email']): Keys
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'keys_test_');
        file_put_contents($tmpFile, json_encode($cacheData));

        // We need to set the cache file path BEFORE the constructor reads it.
        // Since the constructor hardcodes the path, we use a partial mock that
        // stubs fetchKeys (so it won't curl if loadFromCache falls through)
        // and then override the file property.
        //
        // But the constructor flow is: validateUrl → set file → loadFromCache.
        // We can't intercept between "set file" and "loadFromCache" without
        // bypassing the constructor entirely.
        //
        // Alternative: The real cache file (var/data-scrubbing-keys.json)
        // exists in the repo, so the constructor will load from it and never
        // call fetchKeys. For URL validation tests this is fine.
        //
        // For controlled cache data, we construct normally (loads repo cache),
        // then swap the file and re-trigger loadFromCache via reflection.

        $mock = $this->getMockBuilder(Keys::class)
            ->setConstructorArgs([$url])
            ->onlyMethods(['fetchKeys'])
            ->getMock();

        $reflection = new \ReflectionClass(Keys::class);

        $fileProp = $reflection->getProperty('file');
        $fileProp->setValue($mock, $tmpFile);

        $loadFromCache = $reflection->getMethod('loadFromCache');
        $loadFromCache->invoke($mock);

        // Clean up temp file
        @unlink($tmpFile);

        return $mock;
    }
}
