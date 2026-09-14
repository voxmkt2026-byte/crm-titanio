<?php

declare(strict_types=1);

use App\Config;
use App\AppFactory;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

function app_config(): Config
{
    static $config = null;

    if (!$config instanceof Config) {
        $config = Config::load(__DIR__ . '/.env');
    }

    return $config;
}

function app_accounts(): \App\AccountRegistry
{
    return new \App\AccountRegistry(app_config(), __DIR__ . '/storage/settings');
}

function app_factory(): AppFactory
{
    $id = \App\AccountRegistry::validateId($_GET['account'] ?? '1');
    return new AppFactory(app_accounts()->config($id), null, $id);
}

date_default_timezone_set(app_config()->get('APP_TIMEZONE', 'America/Sao_Paulo') ?: 'America/Sao_Paulo');
