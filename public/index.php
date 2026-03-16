<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    $env = $context['APP_ENV'];
    $debug = (bool) ($context['APP_DEBUG'] ?? ('prod' !== $env && 'redis' !== $env));

    return new Kernel($env, $debug);
};
