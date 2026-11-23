<?php

declare(strict_types=1);

$name = getenv('DATABASE_NAME') ?: '';
if (!preg_match('/(?:^test_|_test$)/', $name)) {
    throw new RuntimeException('Workout integration tests require an explicitly named disposable test database.');
}
$passwordFile = getenv('DATABASE_PASSWORD_FILE');

return [
    'driver' => 'pdo_pgsql',
    'host' => getenv('DATABASE_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('DATABASE_PORT') ?: 5432),
    'dbname' => $name,
    'user' => getenv('DATABASE_USER') ?: 'postgres',
    'password' => $passwordFile ? rtrim(file_get_contents($passwordFile), "\r\n") : '',
    'serverVersion' => '18',
];
