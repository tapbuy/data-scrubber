# Data Scrubber

PHP library for anonymizing sensitive data in objects and arrays.

## Installation

```bash
composer require tapbuy/data-scrubber
```

## Usage

```php
use Tapbuy\DataScrubber\Anonymizer;

$anonymizer = new Anonymizer('https://your-api-url.com/keys');

$data = [
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'phonenumber' => [
        'ssn' => '123-45-6789'
    ]
];

$anonymized = $anonymizer->anonymizeObject($data);
```

## API

### Anonymizer Class
```php
class Anonymizer {
    const REDACTED = '<REDACTED>';
    // $redactWith: when null (default) matched values are masked with `*`;
    //              when set (e.g. self::REDACTED) they are replaced with that placeholder.
    // $hashEmails: when true, email values are replaced with their unsalted SHA-256 hash
    //              instead of being masked/redacted. Defaults to false.
    // $matchLeaf:  when true, a key also matches if its leaf (last segment after _ - . / \ or
    //              space) equals a configured key — catches nested fields like
    //              `dwfrm_..._addressFields_email` (leaf `email`). Broader; defaults to false.
    // $recurseJsonStrings: when true, a string value that is itself JSON is decoded, anonymized
    //              with the same settings, and re-encoded (catches PII inside embedded JSON blobs).
    // $redactTokens: when true, any value that looks like a credential (JWT, or `Bearer <jwt>`) is
    //              redacted regardless of field name. Defaults to false.
    public function __construct(Keys|string $keys, ?string $redactWith = null, bool $hashEmails = false, bool $matchLeaf = false, bool $recurseJsonStrings = false, bool $redactTokens = false);
    public function updateKeys(): void;
    public function anonymizeObject(object|array $data): object|array;
}
```

### Keys Class
```php
class Keys {
    public function __construct(string $url);
    public function fetchKeys(): void;
    public function getKeys(): array;
}
```

## Keys Format

Your API endpoint must return:
```json
{
    "success": true,
    "data": ["name", "email", "ssn", "numbers[]"]
}
```
Keys with `[]` suffix indicate array fields that should have all elements anonymized.

Key matching is case-insensitive: both the configured keys and the data field names are
compared in lower case (so `Email`, `C_AdyenLog`, etc. match regardless of case).

## Anonymization Rules

By default, matched values are masked:

- Strings: Replaced with `*` of same length
  ```php
  "John Doe" → "********"
  ```

- Numbers: Random number of same length/type
  ```php
  12345 → 98765
  123.45 → 987.65
  ```

- Redaction mode (`$redactWith`): replace matched values wholesale with a placeholder
  instead of masking/randomizing them
  ```php
  $anonymizer = new Anonymizer($keys, Anonymizer::REDACTED);
  "John Doe" → "<REDACTED>"
  12345      → "<REDACTED>"
  ```

- Email hashing (`$hashEmails`): replace email values with their unsalted SHA-256 hash
  (a stable, one-way identifier) instead of masking/redacting them. Takes precedence
  over the rules above for values that are valid emails.
  ```php
  $anonymizer = new Anonymizer($keys, null, true);
  "john@example.com" → "836f82db99121b3481011f16b49dfa5fbc714a0d1b1b9f784a1ebbbf5b39577f"
  ```

- Leaf matching (`$matchLeaf`): also match a key by its trailing segment, so deeply
  nested form fields are covered without listing every variant
  ```php
  $anonymizer = new Anonymizer($keys /* incl. "email" */, null, true, true);
  "dwfrm_shippingDS_shippingAddress_addressFields_email" matches via leaf "email"
  ```

- Embedded JSON (`$recurseJsonStrings`): when a string value is itself a JSON object or
  array, it is decoded, anonymized with the same settings, and re-encoded — so PII inside
  embedded JSON blobs (e.g. analytics dataLayers) is not left opaque
  ```php
  $anonymizer = new Anonymizer($keys /* incl. "email" */, null, false, false, true);
  '{"email":"john@example.com"}' → '{"email":"****************"}'
  ```

- Token redaction (`$redactTokens`): any string value that looks like a credential — a JWT
  or a `Bearer <jwt>` header — is redacted regardless of field name (replaced with
  `$redactWith` or `<REDACTED>`, never length-masked)
  ```php
  $anonymizer = new Anonymizer($keys, null, false, false, false, true);
  "Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc" → "<REDACTED>"
  ```

- Arrays: If key marked with [], all elements anonymized
  ```php
  'numbers' => [123, 456] → [789, 012]
  ```

## Testing

```bash
composer install
vendor/bin/phpunit
```

## CLI

Update keys via command line:
```bash
php bin/updateKeys.php https://your-api-url.com/keys
```

## Directory Structure
```
data-scrubber/
├── src/
│   ├── Anonymizer.php
│   └── Keys.php
├── tests/
│   ├── AnonymizerTest.php
│   └── KeysTest.php
├── bin/
│   └── updateKeys.php
├── composer.json
└── phpunit.xml.dist
```