<?php

namespace App\Controller\TenantSetupController;

use App\Dto\TenantSetupDTO;
use App\Form\TenantSetupType;
use App\Entity\Entreprise;
use App\Entity\AddressEntreprise;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TenantSetupController extends AbstractController
{
    public function __construct(
        private string $frontendBaseDomain,
        private string $tenantCreationSecretKey
    ) {}

    #[Route('/setup/new-store', name: 'app_tenant_setup')]
    public function setup(
        Request $request,
        TenantConnectionManager $tenantManager,
        TenantEntityManagerProvider $emProvider
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
            
            // A. Validation de la clé secrète de création
            if ($dto->secretKey !== $this->tenantCreationSecretKey) {
                $this->addFlash('danger', 'La clé de sécurité de création est invalide.');
                return $this->render('tenant_setup/form.html.twig', [ 'form' => $form->createView() ]);
            }

            // B. Double Check (Race condition)
            if (!$this->isIdentifierAvailable($dto->code, $tenantManager)) {
                $this->addFlash('danger', 'Ce nom a été pris pendant que vous remplissiez le formulaire.');
                return $this->render('tenant_setup/form.html.twig', [ 'form' => $form->createView() ]);
            }

            // C. Création de l'infrastructure Tenant
            $dbname = 'db_' . $dto->code;
            try {
                $tenantManager->createTenant(
                    $dto->code,
                    $dto->companyName,
                    $dbname,
                    null, // Pas de token GemSuite
                    false,
                    null 
                );
                
                $this->addFlash('info', 'Infrastructure de la boutique créée.');

            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Erreur lors de la création de la boutique : ' . $e->getMessage());
                return $this->redirectToRoute('app_tenant_setup');
            }

            // D. Alimentation des données entreprise dans la base tenant
            try {
                $emProvider->switchTenant($dbname, $dto->code);
                $tenantEm = $emProvider->getEntityManager();

                $entrepriseRepo = $tenantEm->getRepository(Entreprise::class);
                $entreprise = $entrepriseRepo->findOneBy([]) ?? new Entreprise();
                
                $entreprise->setName($dto->companyName);
                $entreprise->setEmail($dto->companyEmail);
                $entreprise->setTel($dto->companyPhone);
                $entreprise->setWebsite($dto->companyWebsite ?: $request->getSchemeAndHttpHost());
                $entreprise->setEin($dto->companyEin);
                $entreprise->setTvaIntracommunautaire($dto->companyTva);
                
                $fullAddress = sprintf('%s%s, %s %s, %s', 
                    $dto->street1,
                    $dto->street2 ? ', ' . $dto->street2 : '',
                    $dto->city,
                    $dto->zip,
                    $dto->country
                );
                $entreprise->setAdress($fullAddress);

                $addressEntreprise = $entreprise->getAddressEntreprise() ?? new AddressEntreprise();
                $addressEntreprise->setStreet1($dto->street1);
                $addressEntreprise->setStreet2($dto->street2 ?? '');
                $addressEntreprise->setCity($dto->city);
                $addressEntreprise->setZip($dto->zip);
                $addressEntreprise->setState($dto->state ?? '');
                $addressEntreprise->setCountry($dto->country);
                $addressEntreprise->setPhone($dto->companyPhone ?? '');
                $addressEntreprise->setEmail($dto->companyEmail);
                $addressEntreprise->setEntreprise($entreprise);

                $tenantEm->persist($entreprise);
                $tenantEm->persist($addressEntreprise);
                $tenantEm->flush();

                $this->addFlash('success', 'Votre boutique a été configurée avec succès !');

            } catch (\Throwable $e) {
                $this->addFlash('warning', 'Boutique créée mais échec de la configuration initiale de l\'entreprise : ' . $e->getMessage());
            }

            // E. Redirection vers la boutique
            $protocol = $request->isSecure() ? 'https' : 'http';
            $finalUrl = sprintf('%s://%s.%s', $protocol, $dto->code, $this->frontendBaseDomain);
            
            return $this->redirect($finalUrl);
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