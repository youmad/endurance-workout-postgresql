<?php

declare(strict_types=1);

/** @param Closure(bool): void $ensure */
return static function (Closure $ensure, string $autoload, string $package): void {
    $connection = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 1, 'dbname' => 'consumer_test']);
    try {
        new Youmad\Endurance\WorkoutPostgresql\DoctrineDbalWorkoutRepository($connection);
        $ensure(!$connection->isConnected());
        $ensure(is_file($package.'/migrations/Version202611070100.php'));
    } finally {
        $connection->close();
    }
};
