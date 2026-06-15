<?php

declare(strict_types=1);

namespace Tapbuy\DataScrubber;

class Anonymizer
{
    /** Conventional placeholder when redacting instead of masking; delimited so it's easy to spot. */
    public const REDACTED = '<REDACTED>';

    private array $keys;
    private Keys $keysObject;
    private ?string $redactWith;
    private bool $hashEmails;

    /**
     * @param Keys|string $keys       A Keys instance or a URL string for backward compatibility
     * @param string|null $redactWith When null (default), matched values are masked with `*` of the
     *                                same length (strings) / randomized (numbers). When set (e.g.
     *                                self::REDACTED), they are replaced wholesale with this string.
     * @param bool        $hashEmails When true, email values are replaced with their unsalted
     *                                SHA-256 hash (a stable, one-way identifier) instead of being
     *                                masked/redacted. Defaults to false.
     */
    public function __construct(Keys|string $keys, ?string $redactWith = null, bool $hashEmails = false)
    {
        $this->keysObject = $keys instanceof Keys ? $keys : new Keys($keys);
        $this->keys = $this->keysObject->getKeys();
        $this->redactWith = $redactWith;
        $this->hashEmails = $hashEmails;
    }

    /**
     * Force-refresh the anonymization keys from the API.
     */
    public function updateKeys(): void
    {
        $this->keysObject->fetchKeys();
        $this->keys = $this->keysObject->getKeys();
    }

    /**
     * Anonymize an object or array recursively.
     */
    public function anonymizeObject(object|array $data): object|array
    {
        return $this->anonymize($data);
    }

    /**
     * Anonymize a value or data structure recursively.
     */
    private function anonymize(mixed $data): mixed
    {
        if (is_object($data)) {
            $anonymizedData = new \stdClass();
            foreach ($data as $key => $value) {
                if (is_array($value) && $this->isArrayKeyMatch($key)) {
                    $anonymizedData->$key = $this->anonymizeArray($value);
                } elseif (is_object($value) || is_array($value)) {
                    $anonymizedData->$key = $this->anonymize($value);
                } elseif ($this->isKeyMatch($key)) {
                    $anonymizedData->$key = $this->anonymizeValue($value);
                } else {
                    $anonymizedData->$key = $value;
                }
            }
            return $anonymizedData;
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                if (is_array($value) && $this->isArrayKeyMatch((string) $key)) {
                    $result[$key] = $this->anonymizeArray($value);
                } elseif (is_object($value) || is_array($value)) {
                    $result[$key] = $this->anonymize($value);
                } elseif ($this->isKeyMatch((string) $key)) {
                    $result[$key] = $this->anonymizeValue($value);
                } else {
                    $result[$key] = $value;
                }
            }
            return $result;
        }

        return $data;
    }

    /**
     * Anonymize a scalar value.
     *
     * When email hashing is enabled, email addresses are replaced with their unsalted
     * SHA-256 hash so the value stays a stable, one-way identifier (a record stays
     * findable from an email without storing the address). Every other matched value
     * — and emails when hashing is disabled — is either redacted with a fixed
     * placeholder (when $redactWith is set) or, by default, masked with `*` of the same
     * length for strings and randomized in place for numbers.
     */
    private function anonymizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            if ($this->hashEmails && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                return hash('sha256', $value);
            }

            return $this->redactWith ?? str_repeat('*', mb_strlen($value));
        }

        if (is_int($value) || is_float($value)) {
            return $this->redactWith ?? $this->anonymizeNumeric($value);
        }

        return $value;
    }

    /**
     * Anonymize a numeric value preserving its type, digit count, and decimal precision.
     */
    private function anonymizeNumeric(int|float $value): int|float
    {
        if (is_float($value)) {
            $str = (string) $value;
            if (str_contains($str, '.')) {
                [$intPart, $decPart] = explode('.', $str, 2);
                return (float) ($this->randomDigitString(strlen($intPart), true) . '.' . $this->randomDigitString(strlen($decPart)));
            }
            return (float) $this->randomDigitString(strlen($str), true);
        }

        return (int) $this->randomDigitString(strlen((string) $value), true);
    }

    /**
     * Anonymize all elements of an array.
     */
    private function anonymizeArray(array $value): array
    {
        return array_map([$this, 'anonymizeValue'], $value);
    }

    /**
     * Check whether a plain key matches any entry in the anonymization key list.
     */
    private function isKeyMatch(string $key): bool
    {
        return in_array(strtolower(str_replace('[]', '', $key)), $this->keys, true);
    }

    /**
     * Check whether an array-typed key (key[]) matches any entry in the anonymization key list.
     */
    private function isArrayKeyMatch(string $key): bool
    {
        return in_array(strtolower($key . '[]'), $this->keys, true);
    }

    /**
     * Generate a string of random decimal digits.
     *
     * @param bool $noLeadingZero When true the first digit is guaranteed to be 1-9.
     */
    private function randomDigitString(int $length, bool $noLeadingZero = false): string
    {
        if ($length <= 0) {
            return '';
        }

        $result = '';
        if ($noLeadingZero && $length > 1) {
            $result .= random_int(1, 9);
            $length--;
        }
        for ($i = 0; $i < $length; $i++) {
            $result .= random_int(0, 9);
        }
        return $result;
    }
}
