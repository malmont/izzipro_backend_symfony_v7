<?php

/*
 * Processus séparé pour le test de concurrence du quota : réserve des crédits sur le tenant de test et affiche
 * « reserved » ou « quota ». Lancé par LandingAiComposeTest avec l'environnement de test déjà en place
 * (variables héritées du processus PHPUnit : bases et Redis de test).
 */

use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 3) . '/.env');

[, $tenantDb, $tenantCode, $cost] = $argv;

$kernel = new App\Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$container->get(TenantEntityManagerProvider::class)->switchTenant($tenantDb, $tenantCode);

try {
    $container->get(LandingAiQuotaService::class)->reserve('edit', 'PresentationGroup', (int) $cost, 'worker@example.invalid', 'concurrence');
    echo 'reserved';
} catch (LandingAiException $e) {
    echo $e->getStatusCode() === 402 ? 'quota' : 'error ' . $e->getStatusCode();
}
