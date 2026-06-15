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
    public function __construct(Keys|string $keys, ?string $redactWith = null, bool $hashEmails = false);
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