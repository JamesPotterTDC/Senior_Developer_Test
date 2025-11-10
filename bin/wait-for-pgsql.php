<?php

declare(strict_types=1);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: 5432);
$db = getenv('DB_DATABASE') ?: 'laravel';
$user = getenv('DB_USERNAME') ?: 'laravel';
$pass = getenv('DB_PASSWORD') ?: 'secret';

$dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $db);

for ($i = 1; $i <= 60; $i++) {
    try {
        new PDO($dsn, $user, $pass, [PDO::ATTR_TIMEOUT => 1]);
        fwrite(STDOUT, "Postgres is ready.\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDOUT, "Waiting for Postgres... attempt {$i}\n");
        sleep(1);
    }
}

fwrite(STDERR, "Postgres did not become ready in time.\n");
exit(1);


