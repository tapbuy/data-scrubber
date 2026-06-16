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
    private bool $matchLeaf;
    private bool $recurseJsonStrings;
    private bool $redactTokens;

    /**
     * @param Keys|string $keys       A Keys instance or a URL string for backward compatibility
     * @param string|null $redactWith When null (default), matched values are masked with `*` of the
     *                                same length (strings) / randomized (numbers). When set (e.g.
     *                                self::REDACTED), they are replaced wholesale with this string.
     * @param bool        $hashEmails When true, email values are replaced with their unsalted
     *                                SHA-256 hash (a stable, one-way identifier) instead of being
     *                                masked/redacted. Defaults to false.
     * @param bool        $matchLeaf  When true, a key also matches if its leaf — the last segment
     *                                after a `_`, `-`, `.`, `/`, `\` or space — equals a configured
     *                                key. This catches nested/prefixed form fields like
     *                                `dwfrm_..._addressFields_email` (leaf `email`) without listing
     *                                every variant. Broader: a generic leaf such as `state` or `city`
     *                                will match any `*_state` / `*_city` key. Defaults to false.
     * @param bool $recurseJsonStrings When true, a string value that is itself JSON (an object or
     *                                array) is decoded, anonymized with the same settings, and
     *                                re-encoded — so PII inside embedded JSON blobs (e.g. analytics
     *                                dataLayers) is not left opaque. Defaults to false.
     * @param bool $redactTokens      When true, any string value that looks like a credential — a
     *                                JWT or a `Bearer <jwt>` header — is redacted regardless of its
     *                                field name (replaced with $redactWith or self::REDACTED, never
     *                                length-masked). Defaults to false.
     */
    public function __construct(
        Keys|string $keys,
        ?string $redactWith = null,
        bool $hashEmails = false,
        bool $matchLeaf = false,
        bool $recurseJsonStrings = false,
        bool $redactTokens = false
    ) {
        $this->keysObject = $keys instanceof Keys ? $keys : new Keys($keys);
        $this->keys = self::normalizeKeys($this->keysObject->getKeys());
        $this->redactWith = $redactWith;
        $this->hashEmails = $hashEmails;
        $this->matchLeaf = $matchLeaf;
        $this->recurseJsonStrings = $recurseJsonStrings;
        $this->redactTokens = $redactTokens;
    }

    /**
     * Force-refresh the anonymization keys from the API.
     */
    public function updateKeys(): void
    {
        $this->keysObject->fetchKeys();
        $this->keys = self::normalizeKeys($this->keysObject->getKeys());
    }

    /**
     * Lower-case the key list so matching is case-insensitive: the data side is already
     * lower-cased before comparison, so a key like "C_AdyenLog" would otherwise never match.
     *
     * @param array<int, mixed> $keys
     * @return string[]
     */
    private static function normalizeKeys(array $keys): array
    {
        return array_map(static fn ($key): string => strtolower((string) $key), $keys);
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
                $anonymizedData->$key = $this->anonymizeEntry((string) $key, $value);
            }
            return $anonymizedData;
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->anonymizeEntry((string) $key, $value);
            }
            return $result;
        }

        return $data;
    }

    /**
     * Anonymize a single key/value entry, applying (in order): array-key matching, recursion into
     * nested structures, key-based value anonymization, embedded-JSON recursion, and token redaction.
     */
    private function anonymizeEntry(string $key, mixed $value): mixed
    {
        if (is_array($value) && $this->isArrayKeyMatch($key)) {
            return $this->anonymizeArray($value);
        }
        if (is_object($value) || is_array($value)) {
            return $this->anonymize($value);
        }
        if ($this->isKeyMatch($key)) {
            return $this->anonymizeValue($value);
        }
        if (is_string($value) && $value !== '') {
            if ($this->recurseJsonStrings && ($value[0] === '{' || $value[0] === '[')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return json_encode($this->anonymize($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }
            if ($this->redactTokens && $this->isSecretToken($value)) {
                return $this->redactWith ?? self::REDACTED;
            }
        }
        return $value;
    }

    /**
     * Whether a string looks like a session credential: a JWT, alone or as a "Bearer <jwt>" value.
     */
    private function isSecretToken(string $value): bool
    {
        return (bool) preg_match('/^Bearer\s+eyJ/i', $value)
            || (bool) preg_match('/^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\./', $value);
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
     * Check whether a plain key matches any entry in the anonymization key list,
     * either by its full (normalized) name or — when leaf matching is enabled — by its
     * trailing segment.
     */
    private function isKeyMatch(string $key): bool
    {
        $normalized = strtolower(str_replace('[]', '', $key));
        if (in_array($normalized, $this->keys, true)) {
            return true;
        }

        if ($this->matchLeaf) {
            $leaf = $this->leafOf($normalized);
            if ($leaf !== $normalized && in_array($leaf, $this->keys, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The trailing segment of a key, after the last `_`, `-`, `.`, `/`, `\` or space.
     */
    private function leafOf(string $normalizedKey): string
    {
        $parts = preg_split('#[_\-./\\\\ ]+#', $normalizedKey);
        $leaf = end($parts);

        return ($leaf === false || $leaf === '') ? $normalizedKey : $leaf;
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
