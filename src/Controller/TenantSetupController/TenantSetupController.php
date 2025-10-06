<?php
namespace App\Controller\TenantSetupController;

use App\Dto\TenantSetupDTO;
use App\Entity\AddressEntreprise;
use App\Entity\Entreprise;
use App\Entity\HomeSlider;
use App\Entity\User;
use App\Form\TenantSetupType;
use App\Services\GemsuiteImporterService\GemsuiteImporter;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder; 

class TenantSetupController extends AbstractController
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    #[Route('/setup/new-store', name: 'app_tenant_setup')]
    public function setup(
        Request $request,
        TenantConnectionManager $tenantManager,
        TenantEntityManagerProvider $emProvider,
        UserPasswordHasherInterface $passwordHasher,
        SluggerInterface $slugger,
        GemsuiteImporter $gemsuiteImporter,
        HttpClientInterface $client,
        GemsuiteImageUrlBuilder $imageUrlBuilder
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
            
            // --- VALIDATION DU JETON GEM-SUITE (SÉCURITÉ) ---
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
            // --- FIN DE LA VALIDATION. SI ON EST ICI, LE JETON EST VALIDE. ---
            try {
                $gemsuiteImporter->checkPrerequisites($dto->gemsuiteToken);
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Impossible de démarrer la création : ' . $e->getMessage());
                return $this->render('tenant_setup/form.html.twig', [ 'form' => $form->createView() ]);
            }

            $dbname = 'db_' . $dto->code;
            try {
                $tenantManager->createTenant($dto->code, $companyData['nom'], $dbname, $dto->gemsuiteToken);
                $this->addFlash('info', 'Infrastructure du tenant créée avec succès.');
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Erreur critique lors de la création du tenant : ' . $e->getMessage());
                return $this->redirectToRoute('app_tenant_setup');
            }

            try {
                $emProvider->switchTenant($dbname, $dto->code);
                $tenantEm = $emProvider->getEntityManager();

                $entreprise = new Entreprise();
                $entreprise->setName($companyData['nom']);
                $entreprise->setEmail($companyData['email'] ?? null);
                $entreprise->setTel($companyData['tel'] ?? null);
                $entreprise->setTvaIntracommunautaire($companyData['tps'] ?? null); 
                $entreprise->setEin($companyData['federal'] ?? null);
                $entreprise->setApropos($companyData['website_about_intro'] ?? null);
                $entreprise->setConditionOfUse($companyData['website_terms'] ?? null);
                $entreprise->setPrivacyPolicy($companyData['website_conf'] ?? null);

                  if (isset($companyData['website_link'])) {
                    $pathParts = explode('/', rtrim($companyData['website_link'], '/'));
                    $identifier = end($pathParts);
                    $entreprise->setGemsuiteIdentifier($identifier);
                }
                
                $logoPath = $companyData['website_logo1'] ?? null;
                $entreprise->setLogo(
                    $imageUrlBuilder->buildUrl($entreprise->getGemsuiteIdentifier(), $logoPath)
                );
              
                if ($companyData && !empty($companyData['adresse'])) {
                    $addressEntreprise = new AddressEntreprise();
                    $addressEntreprise->setStreet1($companyData['adresse']);
                    $addressEntreprise->setStreet2('');
                    $addressEntreprise->setCity($companyData['ville'] ?? '');
                    $addressEntreprise->setState($companyData['prov'] ?? '');
                    $addressEntreprise->setZip($companyData['cp'] ?? '');
                    $addressEntreprise->setCountry($companyData['country'] == 1 ? 'CA' : 'Unknown');
                    $addressEntreprise->setPhone($companyData['tel'] ?? '');
                    $addressEntreprise->setEmail($companyData['email'] ?? '');
                    $entreprise->setAddressEntreprise($addressEntreprise);
                }
                $tenantEm->persist($entreprise);

                if ($companyData) {
                    $homeSlider = new HomeSlider();
                    $homeSlider->setTitle(strip_tags($companyData['website_intro_text1'] ?? 'Bienvenue'));
                    $homeSlider->setDescription(strip_tags($companyData['website_intro_text2'] ?? 'Découvrez nos produits'));
                    $homeSlider->setButtonMessage('Voir la boutique');
                    $homeSlider->setButtonUrl('/shop');
                    $homeSlider->setIsDiplayed(true);

                    $bannerPath = $companyData['website_banner'] ?? null;
                    $homeSlider->setImage(
                        $imageUrlBuilder->buildUrl($entreprise->getGemsuiteIdentifier(), $bannerPath)
                    );
                    $tenantEm->persist($homeSlider);
                }

                // $user = new User();
                // $user->setFirstname($dto->adminName);
                // $user->setLastname('');
                // $user->setEmail($dto->adminEmail);
                // $user->setUsername($dto->adminEmail);
                // $user->setRoles(['ROLE_ADMIN']);
                // $user->setPassword($passwordHasher->hashPassword($user, $dto->plainPassword));
                // $user->setIsVerified(true); 

                // $tenantEm->persist($user);
                
                $tenantEm->flush();
                $this->addFlash('info', 'Profil de l\'entreprise et administrateur créés.');

            } catch (\Throwable $e) {
                $this->addFlash('warning', 'Erreur lors de la création du profil de l\'entreprise et administrateur: ' . $e->getMessage());
            }
            
            if ($dto->gemsuiteToken) {
                try {
                    $gemsuiteImporter->importDataForTenant($dto->code, $dto->gemsuiteToken);
                    $this->addFlash('info', 'Les produits de GEM-SUITE ont été importés.');
                } catch (\Throwable $e) {
                    $this->addFlash('warning', 'Le site a été créé, mais l\'importation des produits a échoué: ' . $e->getMessage());
                }
            }

            $this->addFlash('success', 'Le site pour ' . $companyData['nom'] . ' est prêt !');
            return $this->redirectToRoute('app_home');
        }

        return $this->render('tenant_setup/form.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}