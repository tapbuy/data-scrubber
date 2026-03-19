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
        $tmpFile = sys_get_temp_dir() . '/keys_test_missing_' . uniqid() . '.json';
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        $mock = $this->getMockBuilder(Keys::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchKeys'])
            ->getMock();

        $mock->expects($this->once())->method('fetchKeys');
        $mock->__construct('https://example.com/keys', $tmpFile);
    }

    public function testLoadFromCacheFallsBackToFetchKeysWhenFileIsEmpty(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'keys_test_');
        file_put_contents($tmpFile, '');

        $mock = $this->getMockBuilder(Keys::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchKeys'])
            ->getMock();

        $mock->expects($this->once())->method('fetchKeys');
        $mock->__construct('https://example.com/keys', $tmpFile);

        @unlink($tmpFile);
    }

    public function testLoadFromCacheFallsBackToFetchKeysWhenJsonIsInvalid(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'keys_test_');
        file_put_contents($tmpFile, '{not valid json');

        $mock = $this->getMockBuilder(Keys::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchKeys'])
            ->getMock();

        $mock->expects($this->once())->method('fetchKeys');
        $mock->__construct('https://example.com/keys', $tmpFile);

        @unlink($tmpFile);
    }

    public function testLoadFromCacheDoesNotCallFetchKeysWhenCacheIsValid(): void
    {
        $expectedKeys = ['email', 'phone'];
        $tmpFile = tempnam(sys_get_temp_dir(), 'keys_test_');
        file_put_contents($tmpFile, json_encode($expectedKeys));

        $mock = $this->getMockBuilder(Keys::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchKeys'])
            ->getMock();

        $mock->expects($this->never())->method('fetchKeys');
        $mock->__construct('https://example.com/keys', $tmpFile);

        $this->assertSame($expectedKeys, $mock->getKeys());

        @unlink($tmpFile);
    }

    // ── Helper ──────────────────────────────────────────────────────────

    /**
     * Create a Keys instance with a pre-populated temp cache file so the
     * constructor never hits the network.
     *
     * var/data-scrubbing-keys.json is gitignored (only a .gitkeep is committed),
     * so tests must supply an explicit cache path via the second constructor arg.
     */
    private function createKeysWithCache(string $url, array $cacheData = ['email']): Keys
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'keys_test_');
        file_put_contents($tmpFile, json_encode($cacheData));

        // Pass the pre-populated temp file as the cache path; the constructor
        // will load from it directly without making any network request.
        $keys = new Keys($url, $tmpFile);

        @unlink($tmpFile);

        return $keys;
    }
}
