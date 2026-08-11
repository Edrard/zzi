<?php

$projectRoot = dirname(__DIR__);
$configCache = $projectRoot . '/bootstrap/cache/config.php';

if (file_exists($configCache)) {
    fwrite(STDERR, "Tests aborted: bootstrap/cache/config.php exists.\n");
    fwrite(STDERR, "Clear the production config cache and verify the testing database configuration\n");
    fwrite(STDERR, "before running PHPUnit.\n");
    exit(1);
}

require $projectRoot . '/vendor/autoload.php';
