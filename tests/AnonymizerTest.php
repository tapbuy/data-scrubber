<?php

declare(strict_types=1);

namespace Tapbuy\DataScrubber\Tests;

use PHPUnit\Framework\TestCase;
use Tapbuy\DataScrubber\Anonymizer;
use Tapbuy\DataScrubber\Keys;

class AnonymizerTest extends TestCase
{
    private Anonymizer $anonymizer;

    protected function setUp(): void
    {
        $keysMock = $this->createMock(Keys::class);
        $keysMock->method('getKeys')->willReturn([
            'email',
            'first_name',
            'last_name',
            'phone',
            'password',
            'user_agent[]',
        ]);

        $this->anonymizer = new Anonymizer($keysMock);
    }

    private function createAnonymizerWithKeys(array $keys): Anonymizer
    {
        $keysMock = $this->createMock(Keys::class);
        $keysMock->method('getKeys')->willReturn($keys);

        return new Anonymizer($keysMock);
    }

    // ── String anonymization ────────────────────────────────────────────

    public function testAnonymizesStringValueMatchingKey(): void
    {
        $data = (object) ['email' => 'john@example.com'];
        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertSame(str_repeat('*', strlen('john@example.com')), $result->email);
    }

    public function testAnonymizesEmptyStringValue(): void
    {
        $data = (object) ['email' => ''];
        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertSame('', $result->email);
    }

    public function testAnonymizesMultibyteStringValue(): void
    {
        $data = (object) ['first_name' => 'Éloïse'];
        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertSame(str_repeat('*', mb_strlen('Éloïse')), $result->first_name);
    }

    // ── Numeric anonymization ───────────────────────────────────────────

    public function testAnonymizesIntegerValueMatchingKey(): void
    {
        $data = (object) ['phone' => 12345];
        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertIsInt($result->phone);
        $this->assertSame(strlen('12345'), strlen((string) $result->phone));
        $this->assertNotSame(12345, $result->phone);
    }

    public function testAnonymizesFloatValuePreservingDecimalStructure(): void
    {
        $anonymizer = $this->createAnonymizerWithKeys(['price']);
        $data = (object) ['price' => 12.34];
        $result = $anonymizer->anonymizeObject($data);

        $this->assertIsFloat($result->price);
        $parts = explode('.', (string) $result->price);
        $this->assertCount(2, $parts);
        // Integer part has same digit count
        $this->assertSame(strlen('12'), strlen($parts[0]));
    }

    public function testAnonymizesFloatWithoutDecimalPart(): void
    {
        $anonymizer = $this->createAnonymizerWithKeys(['price']);
        $data = (object) ['price' => 100.0];
        $result = $anonymizer->anonymizeObject($data);

        // 100.0 casts to string "100" (no decimal point), so anonymizeNumeric
        // treats it as a float without a dot
        $this->assertIsFloat($result->price);
    }

    // ── Non-matching keys ───────────────────────────────────────────────

    public function testLeavesNonMatchingKeysUntouched(): void
    {
        $data = (object) ['product_name' => 'Widget', 'quantity' => 5, 'active' => true];
        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertSame('Widget', $result->product_name);
        $this->assertSame(5, $result->quantity);
        $this->assertTrue($result->active);
    }

    // ── Boolean and null values ─────────────────────────────────────────

    public function testLeavesBooleanValueIntactEvenWhenKeyMatches(): void
    {
        $data = (object) ['email' => true];
        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertTrue($result->email);
    }

    public function testLeavesNullValueIntactEvenWhenKeyMatches(): void
    {
        $data = (object) ['email' => null];
        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertNull($result->email);
    }

    // ── Case insensitivity ──────────────────────────────────────────────

    public function testKeyMatchingIsCaseInsensitive(): void
    {
        $data = (object) ['Email' => 'test@test.com', 'FIRST_NAME' => 'John'];
        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertSame(str_repeat('*', strlen('test@test.com')), $result->Email);
        $this->assertSame(str_repeat('*', strlen('John')), $result->FIRST_NAME);
    }

    // ── Nested object recursion ─────────────────────────────────────────

    public function testAnonymizesNestedObjects(): void
    {
        $data = (object) [
            'user' => (object) [
                'email' => 'a@b.com',
                'first_name' => 'Alice',
            ],
            'product_name' => 'Widget',
        ];

        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertSame(str_repeat('*', strlen('a@b.com')), $result->user->email);
        $this->assertSame(str_repeat('*', strlen('Alice')), $result->user->first_name);
        $this->assertSame('Widget', $result->product_name);
    }

    // ── Nested array recursion ──────────────────────────────────────────

    public function testAnonymizesNestedArrays(): void
    {
        $data = [
            'customer' => [
                'email' => 'x@y.com',
                'last_name' => 'Doe',
            ],
            'order_ref' => 'ABC-123',
        ];

        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertSame(str_repeat('*', strlen('x@y.com')), $result['customer']['email']);
        $this->assertSame(str_repeat('*', strlen('Doe')), $result['customer']['last_name']);
        $this->assertSame('ABC-123', $result['order_ref']);
    }

    // ── Mixed nesting ───────────────────────────────────────────────────

    public function testAnonymizesMixedObjectArrayNesting(): void
    {
        $data = (object) [
            'customers' => [
                (object) ['email' => 'a@a.com'],
                (object) ['email' => 'b@b.com'],
            ],
        ];

        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertSame(str_repeat('*', strlen('a@a.com')), $result->customers[0]->email);
        $this->assertSame(str_repeat('*', strlen('b@b.com')), $result->customers[1]->email);
    }

    // ── Array key matching (key[] pattern) ──────────────────────────────

    public function testAnonymizesArrayValuesWhenArrayKeyMatches(): void
    {
        $data = (object) [
            'user_agent' => ['Mozilla/5.0', 'Chrome/91'],
        ];

        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertSame(str_repeat('*', strlen('Mozilla/5.0')), $result->user_agent[0]);
        $this->assertSame(str_repeat('*', strlen('Chrome/91')), $result->user_agent[1]);
    }

    public function testArrayKeyMatchWorksWithArrayInput(): void
    {
        $data = [
            'user_agent' => ['value1', 'value2'],
        ];

        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertSame(str_repeat('*', strlen('value1')), $result['user_agent'][0]);
        $this->assertSame(str_repeat('*', strlen('value2')), $result['user_agent'][1]);
    }

    public function testNonMatchingArrayKeyIsRecursedNotAnonymized(): void
    {
        $data = (object) [
            'items' => [
                (object) ['email' => 'x@y.com', 'sku' => 'SKU1'],
            ],
        ];

        $result = $this->anonymizer->anonymizeObject($data);

        // items[] is not in keys, so it's recursed into, not array-anonymized
        $this->assertSame(str_repeat('*', strlen('x@y.com')), $result->items[0]->email);
        $this->assertSame('SKU1', $result->items[0]->sku);
    }

    // ── Empty data ──────────────────────────────────────────────────────

    public function testEmptyObjectReturnsEmptyObject(): void
    {
        $result = $this->anonymizer->anonymizeObject(new \stdClass());

        $this->assertEquals(new \stdClass(), $result);
    }

    public function testEmptyArrayReturnsEmptyArray(): void
    {
        $result = $this->anonymizer->anonymizeObject([]);

        $this->assertSame([], $result);
    }

    // ── Key with brackets in source data ────────────────────────────────

    public function testKeyWithBracketsIsStrippedForMatching(): void
    {
        // isKeyMatch strips [] from the key before comparing
        $data = (object) ['email[]' => 'test@test.com'];
        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertSame(str_repeat('*', strlen('test@test.com')), $result->{'email[]'});
    }

    // ── updateKeys ──────────────────────────────────────────────────────

    public function testUpdateKeysRefreshesFromKeysObject(): void
    {
        $keysMock = $this->createMock(Keys::class);

        // First call during construction
        $keysMock->method('getKeys')
            ->willReturnOnConsecutiveCalls(
                ['email'],
                ['email', 'phone']
            );

        $keysMock->expects($this->once())->method('fetchKeys');

        $anonymizer = new Anonymizer($keysMock);

        // Before update — only 'email' is matched
        $data = (object) ['email' => 'a@b.com', 'phone' => '1234'];
        $result = $anonymizer->anonymizeObject($data);
        $this->assertSame(str_repeat('*', strlen('a@b.com')), $result->email);
        $this->assertSame('1234', $result->phone); // not yet in keys

        // After update — 'phone' should also be matched
        $anonymizer->updateKeys();
        $result2 = $anonymizer->anonymizeObject($data);
        $this->assertSame(str_repeat('*', strlen('1234')), $result2->phone);
    }

    // ── Multiple matching keys in same object ───────────────────────────

    public function testAnonymizesMultipleMatchingKeysInSameObject(): void
    {
        $data = (object) [
            'email' => 'john@doe.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'password' => 's3cr3t',
            'role' => 'admin',
        ];

        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertSame(str_repeat('*', strlen('john@doe.com')), $result->email);
        $this->assertSame(str_repeat('*', strlen('John')), $result->first_name);
        $this->assertSame(str_repeat('*', strlen('Doe')), $result->last_name);
        $this->assertSame(str_repeat('*', strlen('s3cr3t')), $result->password);
        $this->assertSame('admin', $result->role);
    }

    // ── Deeply nested data ──────────────────────────────────────────────

    public function testAnonymizesDeeplyNestedData(): void
    {
        $data = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'email' => 'deep@test.com',
                    ],
                ],
            ],
        ];

        $result = $this->anonymizer->anonymizeObject($data);

        $this->assertSame(
            str_repeat('*', strlen('deep@test.com')),
            $result['level1']['level2']['level3']['email']
        );
    }
}
