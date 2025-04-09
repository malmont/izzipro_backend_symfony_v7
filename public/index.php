<?php

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

if ('prod' === $_SERVER['APP_ENV']) {
    Request::setTrustedProxies(
        ['172.17.0.0/16'],
        Request::HEADER_X_FORWARDED_ALL
    );
}

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
