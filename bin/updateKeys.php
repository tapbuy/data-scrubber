#!/usr/bin/env php
<?php

// Support both: installed as a Composer dependency and standalone dev usage.
foreach ([__DIR__ . '/../../../autoload.php', __DIR__ . '/../vendor/autoload.php'] as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;
        break;
    }
}

use Tapbuy\DataScrubber\Keys;

$url = $argv[1] ?? '';

if (empty($url)) {
    echo "Usage: php bin/updateKeys.php <url>\n";
    exit(1);
}

try {
    $fetcher = new Keys($url);
    $fetcher->fetchKeys();
    echo "Keys updated\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
