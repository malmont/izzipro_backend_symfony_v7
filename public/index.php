<?php

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// Utilise getenv() pour récupérer l'environnement de production et définir les trusted proxies
if ('prod' === getenv('APP_ENV')) {
    Request::setTrustedProxies(
        ['172.19.0.0/16'], // Plage correspondant à ton réseau Docker (d'après docker inspect)
        Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO
    );
}

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
