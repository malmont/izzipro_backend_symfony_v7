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
    public function __construct(
        private string $frontendBaseDomain,
        private string $gemsuiteApiUrl
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
        $subdomain = null;

        // --- 1. Extraction du sous-domaine ---
        // On vérifie si on est bien sur un sous-domaine du domaine principal
        // ex: host = "client.gem-portal-dev.com", base = "gem-portal-dev.com"
        if (str_ends_with($host, $this->frontendBaseDomain) && $host !== $this->frontendBaseDomain) {
            // On retire le domaine de base pour isoler le sous-domaine
            // On retire aussi le dernier point (d'où le +1)
            $prefix = substr($host, 0, -(strlen($this->frontendBaseDomain) + 1));
            
            // Si on a "www.client", on prend "client", sinon on prend tout le préfixe
            $parts = explode('.', $prefix);
            $subdomain = end($parts); 
        }

        // Si extraction échouée ou sous-domaine invalide/réservé
        if (!$subdomain || in_array($subdomain, ['www', 'api', 'admin', 'mail'])) {
            $this->addFlash('danger', 'L\'accès à cette page doit se faire via un sous-domaine valide de votre nouveau site.');
            return $this->redirectToRoute('app_home'); 
        }

        // --- 2. Vérification Disponibilité (Code + DB + Custom Domain) ---
        try {
            $pdoMaster = $tenantManager->getPdoMaster();
            
            // Mise à jour de la requête pour inclure custom_domain
            $stmt = $pdoMaster->prepare('
                SELECT 1 FROM tenants 
                WHERE code = :code 
                OR dbname = :dbname
                OR custom_domain = :host
            ');
            
            $stmt->execute([
                'code' => $subdomain, 
                'dbname' => 'db_' . $subdomain,
                'host' => $host // On vérifie si le host actuel n'est pas déjà enregistré comme custom_domain
            ]);

            if ($stmt->fetch()) {
                $this->addFlash('danger', 'Ce sous-domaine ou nom de site est déjà utilisé. Veuillez en choisir un autre.');
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
        if ($form->has('subdomain_display')) {
            $form->get('subdomain_display')->setData($subdomain);
        }
        
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $companyData = null;
            if (!$dto->gemsuiteToken) {
                $this->addFlash('danger', 'Le jeton d\'authentification GEM-SUITE est obligatoire.');
                return $this->render('tenant_setup/form.html.twig', [ 'form' => $form->createView() ]);
            }
            try {
                $response = $client->request('GET', $this->gemsuiteApiUrl . 'company', [
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
                // MODIF: Ajout du null en dernier argument pour le custom_domain
                $tenantManager->createTenant(
                    $dto->code, 
                    $companyData['nom'], 
                    $dbname, 
                    $dto->gemsuiteToken, 
                    null // Custom domain est null à la création auto
                );
                
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

            // --- LOGIQUE SYNC JOB INCHANGÉE ---
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

            // MODIF: Gestion du protocole dynamique
            $protocol = $request->isSecure() ? 'https' : 'http';
            $finalUrl = sprintf('%s://%s.%s', $protocol, $dto->code, $this->frontendBaseDomain);
            
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