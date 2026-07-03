<?php

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
    ) {}

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

        $cleanHost = explode(':', $host)[0];

        // Cas Localhost
        if ($cleanHost === 'localhost' || $cleanHost === '127.0.0.1') {
            $subdomain = 'localtest'; 
        } 
        else {

            $parts = explode('.', $cleanHost);
            
            if (count($parts) >= 3) {
                if ($parts[0] !== 'www') {
                    $subdomain = $parts[0];
                } elseif (isset($parts[1])) {
                    $subdomain = $parts[1];
                }
            } elseif (count($parts) === 2 && $parts[1] === 'localhost') {
                $subdomain = $parts[0];
            }
        }

        // Si extraction échouée ou sous-domaine réservé
        if (!$subdomain || in_array($subdomain, ['www', 'api', 'admin', 'mail', 'backend'])) {
            $this->addFlash('danger', 'Accès invalide. Veuillez utiliser une URL de type : nomboutique.votre-domaine.com/setup/new-store');
            return $this->redirectToRoute('app_home'); 
        }

        // --- 2. VÉRIFICATION DISPONIBILITÉ (Code + DB + Custom Domain) ---
        try {
            if (!$this->isIdentifierAvailable($subdomain, $tenantManager)) {
                $this->addFlash('danger', "Le site '$subdomain' existe déjà ou est réservé. Veuillez en choisir un autre.");
                return $this->redirectToRoute('app_home'); 
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erreur système lors de la vérification : ' . $e->getMessage());
            return $this->redirectToRoute('app_home');
        }
        
        // --- 3. GESTION DU FORMULAIRE ---
        $dto = new TenantSetupDTO();
        $dto->subdomain = $subdomain;
        $dto->code = $subdomain;

        $form = $this->createForm(TenantSetupType::class, $dto);
        if ($form->has('subdomain_display')) {
            $form->get('subdomain_display')->setData($subdomain);
        }
        
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            // A. Validation API GemSuite
            $companyData = null;
            if (!$dto->gemsuiteToken) {
                $this->addFlash('danger', 'Le jeton GEM-SUITE est obligatoire.');
                return $this->render('tenant_setup/form.html.twig', [ 'form' => $form->createView() ]);
            }
            try {
                $response = $client->request('GET', $this->gemsuiteApiUrl . 'company', [
                    'auth_bearer' => $dto->gemsuiteToken,
                ]);
                if ($response->getStatusCode() !== 200) { throw new \Exception('Jeton invalide ou erreur API.'); }
                
                $companyData = $response->toArray()['data'][0] ?? null;
                if (!$companyData) { throw new \Exception('Aucune entreprise trouvée.'); }
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Erreur API GemSuite : ' . $e->getMessage());
                return $this->render('tenant_setup/form.html.twig', [ 'form' => $form->createView() ]);
            }

            // B. Vérification Pré-requis
            try {
                $gemsuiteImporter->checkPrerequisites($dto->gemsuiteToken);
            } catch (\Throwable $e) {
                $this->addFlash('danger', $e->getMessage());
                return $this->render('tenant_setup/form.html.twig', [ 'form' => $form->createView() ]);
            }

            // C. Double Check (Race condition)
            if (!$this->isIdentifierAvailable($dto->code, $tenantManager)) {
                $this->addFlash('danger', 'Ce nom a été pris pendant que vous remplissiez le formulaire.');
                return $this->render('tenant_setup/form.html.twig', [ 'form' => $form->createView() ]);
            }

            // D. Création du Tenant
            $dbname = 'db_' . $dto->code;
            $tenantId = null;
            try {

                $tenantManager->createTenant(
                    $dto->code,
                    $companyData['nom'],
                    $dbname,
                    $dto->gemsuiteToken,
                    false,
                    null 
                );
                
                $this->addFlash('info', 'Infrastructure créée.');

                // Récupération ID Tenant pour le Job
                $pdoMaster = $tenantManager->getPdoMaster();
                $stmt = $pdoMaster->prepare('SELECT id FROM tenants WHERE code = :code');
                $stmt->execute(['code' => $dto->code]);
                $tenantId = $stmt->fetchColumn();
                if (!$tenantId) throw new \Exception("ID tenant introuvable après création.");

            } catch (\Throwable $e) {
                $this->addFlash('error', 'Erreur création tenant : ' . $e->getMessage());
                return $this->redirectToRoute('app_tenant_setup');
            }

            // E. Lancement du Job Messenger
            try {
                $emProvider->switchTenant($dbname, $dto->code);
                $tenantEm = $emProvider->getEntityManager();

                $syncJob = new SyncJob();
                $syncJob->setStatus('pending');
                $syncJob->setCurrentStep('Initialisation...');
                $tenantEm->persist($syncJob);
                $tenantEm->flush(); 

                $message = new StartGemsuiteImportJob($tenantId, $dto->gemsuiteToken, $syncJob->getId());
                $messageBus->dispatch($message);
                
                $this->addFlash('info', 'Importation démarrée en arrière-plan.');

            } catch (\Throwable $e) {
                $this->addFlash('warning', 'Site créé mais échec du démarrage de l\'import : ' . $e->getMessage());
            }

            $this->addFlash('success', 'Site prêt !');

            // F. Redirection vers la page de statut
            // On utilise $frontendBaseDomain pour l'URL finale, mais la redirection actuelle se fait sur le domaine courant
            $protocol = $request->isSecure() ? 'https' : 'http';
            
            // L'URL finale vers laquelle l'utilisateur ira une fois fini (sur le vrai domaine)
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

    /**
     * Vérifie si l'identifiant est disponible (Code, DB, Custom Domain)
     */
    private function isIdentifierAvailable(string $identifier, TenantConnectionManager $tenantManager): bool
    {
        $pdoMaster = $tenantManager->getPdoMaster();
        $dbname = 'db_' . $identifier;
        
        $sql = 'SELECT 1 FROM tenants WHERE code = :identifier OR dbname = :dbname OR custom_domain = :identifier';
        
        $stmt = $pdoMaster->prepare($sql);
        $stmt->execute(['identifier' => $identifier, 'dbname' => $dbname]);
        
        return $stmt->fetch() === false; 
    }
}