<?php

declare(strict_types=1);

namespace Tapbuy\DataScrubber;

class Anonymizer
{
    private array $keys;
    private Keys $keysObject;

    /**
     * @param Keys|string $keys A Keys instance or a URL string for backward compatibility
     */
    public function __construct(Keys|string $keys)
    {
        $this->keysObject = $keys instanceof Keys ? $keys : new Keys($keys);
        $this->keys = $this->keysObject->getKeys();
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
     * Anonymize a scalar value preserving its type and length.
     */
    private function anonymizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_repeat('*', mb_strlen($value));
        }

        if (is_int($value) || is_float($value)) {
            return $this->anonymizeNumeric($value);
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
