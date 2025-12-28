<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (!class_exists(Dotenv::class)) {
    throw new RuntimeException('Please run "composer require symfony/dotenv" to load the .env files.');
}

(new Dotenv())->usePutenv()->bootEnv(dirname(__DIR__).'/.env', 'test');

$databaseUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? null;
if ($databaseUrl && str_contains($databaseUrl, 'sqlite:///%kernel.project_dir%/var/test.db')) {
    $testDbPath = dirname(__DIR__).'/var/test.db';
    if (file_exists($testDbPath)) {
        unlink($testDbPath);
    }
}
