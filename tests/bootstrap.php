<?php

$projectRoot = dirname(__DIR__);
$configCache = $projectRoot . '/bootstrap/cache/config.php';

if (file_exists($configCache)) {
    fwrite(STDERR, "Tests aborted: bootstrap/cache/config.php exists.\n");
    fwrite(STDERR, "Use the protected config-cache backup/remove/restore protocol before running PHPUnit.\n");
    exit(1);
}

$expectedRedisEnvironment = [
    'APP_ENV' => 'testing',
    'PHPUNIT_REDIS_ISOLATED' => '1',
    'REDIS_HOST' => '127.0.0.1',
    'REDIS_PORT' => '6399',
    'REDIS_DB' => '0',
    'REDIS_CACHE_DB' => '1',
    'REDIS_INLINE_IMAGE_DB' => '2',
    'REDIS_PREFIX' => 'work_phpunit_',
];

foreach ($expectedRedisEnvironment as $name => $expectedValue) {
    $actualValue = getenv($name);

    if ($actualValue !== $expectedValue) {
        fwrite(
            STDERR,
            sprintf(
                "Tests aborted: unsafe PHPUnit Redis environment (%s=%s, expected %s).\n",
                $name,
                $actualValue === false ? '<unset>' : $actualValue,
                $expectedValue,
            ),
        );
        fwrite(STDERR, "Run PHPUnit through scripts/phpunit-safe.sh.\n");
        exit(1);
    }
}

require $projectRoot . '/vendor/autoload.php';
