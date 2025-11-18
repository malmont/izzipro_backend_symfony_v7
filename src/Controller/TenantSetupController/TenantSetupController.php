<?php
// src/Controller/TenantSetupController/TenantSetupController.php

namespace App\Controller\TenantSetupController;

use App\Dto\TenantSetupDTO;
use App\Form\TenantSetupType;
use App\Services\GemsuiteImporterService\GemsuiteImporter;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Entity\SyncJob;
use App\Message\StartGemsuiteImportJob;
use Symfony\Component\Messenger\MessageBusInterface;

class TenantSetupController extends AbstractController
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    public function __construct(
        private string $frontendBaseDomain
    ) {
    }

    #[Route('/setup/new-store', name: 'app_tenant_setup')]
    public function setup(
        Request $request,
        TenantConnectionManager $tenantManager,
        TenantEntityManagerProvider $emProvider,
        GemsuiteImporter $gemsuiteImporter,
        HttpClientInterface $client,
        MessageBusInterface $messageBus
    ): Response {
        
        $host = $request->getHost();
        $parts = explode('.', $host);
        if (count($parts) < 3) {
            $this->addFlash('danger', 'L\'accès à cette page doit se faire via le sous-domaine de votre nouveau site (ex: monclient.votredomaine.com).');
            return $this->redirectToRoute('app_home'); 
        }
        $subdomain = $parts[0];
          try {
            $pdoMaster = $tenantManager->getPdoMaster();
            $stmt = $pdoMaster->prepare('SELECT 1 FROM tenants WHERE code = :code OR dbname = :dbname');
            $stmt->execute(['code' => $subdomain, 'dbname' => 'db_' . $subdomain]);
            if ($stmt->fetch()) {
                $this->addFlash('danger', 'Ce sous-domaine est déjà utilisé ou réservé. Veuillez en choisir un autre.');
                return $this->redirectToRoute('app_home'); 
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erreur lors de la vérification du sous-domaine : ' . $e->getMessage());
            return $this->redirectToRoute('app_home');
        }
        
        $dto = new TenantSetupDTO();
        $dto->subdomain = $subdomain;
        $dto->code = $subdomain;

        $form = $this->createForm(TenantSetupType::class, $dto);
        $form->get('subdomain_display')->setData($subdomain);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $companyData = null;
            if (!$dto->gemsuiteToken) {
                $this->addFlash('danger', 'Le jeton d\'authentification GEM-SUITE est obligatoire pour créer un nouveau site.');
                return $this->render('tenant_setup/form.html.twig', [ 'form' => $form->createView() ]);
            }
            try {
                $response = $client->request('GET', self::GEMSUITE_API_URL . 'company', [
                    'auth_bearer' => $dto->gemsuiteToken,
                ]);
                if ($response->getStatusCode() !== 200) {
                     throw new \Exception('Le jeton GEM-SUITE est invalide ou l\'API a retourné une erreur.');
                }
                $companyData = $response->toArray()['data'][0] ?? null;
                if (!$companyData) {
                    throw new \Exception('Aucune donnée d\'entreprise trouvée pour ce jeton GEM-SUITE.');
                }
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Erreur de validation GEM-SUITE : ' . $e->getMessage());
                return $this->render('tenant_setup/form.html.twig', [ 'form' => $form->createView() ]);
            }

            try {
                $gemsuiteImporter->checkPrerequisites($dto->gemsuiteToken);
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Impossible de démarrer la création : ' . $e->getMessage());
                return $this->render('tenant_setup/form.html.twig', [ 'form' => $form->createView() ]);
            }

            $dbname = 'db_' . $dto->code;
            $tenantId = null;
            try {
                $tenantManager->createTenant($dto->code, $companyData['nom'], $dbname, $dto->gemsuiteToken);
                $this->addFlash('info', 'Infrastructure du tenant créée avec succès.');

                $pdoMaster = $tenantManager->getPdoMaster();
                $stmt = $pdoMaster->prepare('SELECT id FROM tenants WHERE code = :code');
                $stmt->execute(['code' => $dto->code]);
                $tenantId = $stmt->fetchColumn();
                if (!$tenantId) { throw new \Exception("Impossible de retrouver l'ID du tenant après création."); }

            } catch (\Throwable $e) {
                $this->addFlash('error', 'Erreur critique lors de la création du tenant : ' . $e->getMessage());
                return $this->redirectToRoute('app_tenant_setup');
            }

            if ($dto->gemsuiteToken) {
                try {
                    $emProvider->switchTenant($dbname, $dto->code);
                    $tenantEm = $emProvider->getEntityManager();

                    $syncJob = new SyncJob();
                    $syncJob->setStatus('pending');
                    $syncJob->setCurrentStep('Initialisation du job...');
                    $tenantEm->persist($syncJob);
                    $tenantEm->flush(); 

                    $message = new StartGemsuiteImportJob(
                        $tenantId, 
                        $dto->gemsuiteToken,
                        $syncJob->getId() 
                    );
                    
                    $messageBus->dispatch($message);
                    
                    $this->addFlash('info', 'Le site est prêt. La création démarre en arrière-plan.');

                } catch (\Throwable $e) {
                    $this->addFlash('warning', 'Le site a été créé, mais l\'importation n\'a pas pu démarrer: ' . $e->getMessage());
                }
            }

            $this->addFlash('success', 'Le site pour ' . $companyData['nom'] . ' est prêt !');

            $finalUrl = sprintf('https://%s.%s', $dto->code, $this->frontendBaseDomain);
                        return $this->redirectToRoute('app_setup_status', [
                'tenantCode' => $dto->code,
                'syncJobId' => $syncJob->getId(),
                'finalUrl' => base64_encode($finalUrl) 
            ]);
        }

        return $this->render('tenant_setup/form.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}