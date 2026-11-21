<?php

namespace App\Tests\Functional\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * La route d'aperçu des pages d'erreur de Symfony (`/_error/{code}`, nom `_preview_error`) reste FERMÉE (retirée le 20/11/2026, commit
 * da17ba34 : la production tourne en APP_ENV=dev et la route répondait en anonyme). Une recette de paquet ou une montée de version qui
 * remettrait `config/routes/framework.yaml` d'origine la rouvrirait : cet essai le dit (demande du chef, 21/11/2026).
 */
class ErrorPreviewRouteClosedTest extends WebTestCase
{
    public function testTheErrorPreviewRouteDoesNotExistAndItsPathAnswers404(): void
    {
        $client = static::createClient();
        $router = static::getContainer()->get(RouterInterface::class);
        $this->assertNull($router->getRouteCollection()->get('_preview_error'), 'aucune route _preview_error');
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $this->assertStringStartsNotWith('/_error', $route->getPath(), "la route $name ne doit pas servir /_error");
        }
        foreach (['/_error/500', '/_error/404', '/_error/500.json', '/index.php/_error/500'] as $path) {
            $client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'text/html']);
            $this->assertSame(404, $client->getResponse()->getStatusCode(), $path);
        }
    }

    /**
     * La recette d'origine importe les routes d'erreur sous `when@dev`, que l'environnement des essais ne charge pas : la production
     * (APP_ENV=dev), elle, les chargerait. D'où la lecture des fichiers : aucune ligne ACTIVE (hors commentaire) n'importe ces routes.
     */
    public function testNoRoutingFileImportsTheErrorRoutesInAnyEnvironment(): void
    {
        $dir = dirname(__DIR__, 3) . '/config';
        $files = array_merge(glob($dir . '/routes/*.{yaml,yml,php,xml}', \GLOB_BRACE) ?: [], glob($dir . '/routes/*/*.{yaml,yml,php,xml}', \GLOB_BRACE) ?: [], [$dir . '/routes.yaml']);
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            foreach (file($file) ?: [] as $n => $line) {
                $code = ltrim($line);
                if ($code === '' || str_starts_with($code, '#') || str_starts_with($code, '//') || str_starts_with($code, '*')) {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression('#routing/errors\.(xml|php)|_error\b#', $line, basename($file) . ':' . ($n + 1));
            }
        }
    }
}
