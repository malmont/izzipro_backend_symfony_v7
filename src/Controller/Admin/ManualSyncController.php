<?php

namespace App\Controller\Admin;

use App\Services\GemsuiteImporterService\GemsuiteCompanySyncHandler;
use App\Services\GemsuiteImporterService\GemsuiteSyncHandler;
use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ManualSyncController extends AbstractController
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    public function __construct(
        private GemsuiteSyncHandler $syncHandler,
        private GemsuiteCompanySyncHandler $companySyncHandler,
        private TenantConnectionManager $tenantManager,
        private HttpClientInterface $client,
        private LoggerInterface $logger
    ) {
    }

    #[Route('/admin/sync-gemsuite', name: 'admin_sync_gemsuite')]
    public function syncAll(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $tenantCode = $this->tenantManager->getCurrentTenantCode();
        if (!$tenantCode) {
            $this->addFlash('danger', 'Impossible de déterminer le tenant actuel.');
            return $this->redirectToRoute('admin'); 
        }

        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) {
            $this->addFlash('danger', sprintf('Aucun token GEM-SUITE n\'est configuré pour le tenant "%s".', $tenantCode));
            return $this->redirectToRoute('admin');
        }

        $this->addFlash('info', 'Lancement de la synchronisation complète pour le tenant ' . $tenantCode);

        try {
            // Synchronisation de l'entreprise
            $this->companySyncHandler->handleCompanyUpdate($tenantCode);
            $this->addFlash('success', 'Informations de l\'entreprise synchronisées.');

            // Récupération et synchronisation des catégories
            $categoriesResponse = $this->client->request('GET', self::GEMSUITE_API_URL . 'categories', ['auth_bearer' => $token]);
            $categoriesContent = $categoriesResponse->getContent(false);

            if (!empty($categoriesContent)) {
                $categories = $categoriesResponse->toArray()['data'] ?? [];
                foreach ($categories as $category) {
                    $this->syncHandler->handleCategoryUpdate($tenantCode, $category['id']);
                }
                $this->addFlash('success', sprintf('%d catégories synchronisées.', count($categories)));
            } else {
                $this->addFlash('warning', 'Aucune catégorie à synchroniser. La réponse de l\'API était vide.');
            }

            // Récupération et synchronisation des produits
            $productsResponse = $this->client->request('GET', self::GEMSUITE_API_URL . 'products', ['auth_bearer' => $token]);
            $productsContent = $productsResponse->getContent(false);

            if (!empty($productsContent)) {
                $products = $productsResponse->toArray()['data'] ?? [];
                foreach ($products as $product) {
                    $this->syncHandler->handleProductUpdate($tenantCode, $product['id']);
                }
                $this->addFlash('success', sprintf('%d produits synchronisés.', count($products)));
            } else {
                $this->addFlash('warning', 'Aucun produit à synchroniser. La réponse de l\'API était vide.');
            }

        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de la synchronisation manuelle : ' . $e->getMessage());
            $this->addFlash('danger', 'Une erreur est survenue pendant la synchronisation. Consultez les logs pour plus de détails.');
        }

        return $this->redirectToRoute('admin');
    }
}
