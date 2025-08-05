<?php
namespace App\Controller\TenantSetupController;

use App\Dto\TenantSetupDTO;
use App\Entity\AddressEntreprise; // <-- AJOUTER
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
        HttpClientInterface $client
    ): Response {
        $dto = new TenantSetupDTO();
        $form = $this->createForm(TenantSetupType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $dbname = 'db_' . $dto->code;
            try {
                $tenantManager->createTenant($dto->code, $dto->companyName, $dbname, $dto->gemsuiteToken);
                $this->addFlash('info', 'Infrastructure du tenant créée avec succès.');
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Erreur critique lors de la création du tenant : ' . $e->getMessage());
                return $this->redirectToRoute('app_tenant_setup');
            }

            try {
                $emProvider->switchTenant($dbname, $dto->code);
                $tenantEm = $emProvider->getEntityManager();

                $companyData = null;
                if ($dto->gemsuiteToken) {
                    try {
                        $response = $client->request('GET', self::GEMSUITE_API_URL . 'company', [
                            'auth_bearer' => $dto->gemsuiteToken,
                        ]);
                        $companyData = $response->toArray()['data'][0] ?? null;
                    } catch (\Throwable $e) {
                        $this->addFlash('warning', 'Impossible de récupérer les informations de l\'entreprise depuis GEM-SUITE : ' . $e->getMessage());
                    }
                }

                $entreprise = new Entreprise();
                $entreprise->setName($companyData['nom'] ?? $dto->companyName);
                $entreprise->setEmail($companyData['email'] ?? $dto->companyEmail);
                $entreprise->setTel($companyData['tel'] ?? null);
                $entreprise->setTvaIntracommunautaire($dto->companyTva);
                $entreprise->setEin($dto->companyEin);
                $entreprise->setLogo($companyData['website_logo1'] ?? null);
                $entreprise->setApropos($companyData['website_about_intro'] ?? null);
                $entreprise->setConditionOfUse($companyData['website_terms'] ?? null);
                $entreprise->setPrivacyPolicy($companyData['website_conf'] ?? null);

                if (isset($companyData['website_link'])) {
                    $pathParts = explode('/', rtrim($companyData['website_link'], '/'));
                    $identifier = end($pathParts);
                    $entreprise->setGemsuiteIdentifier($identifier);
                }
                
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

                    if (!empty($companyData['website_banner']) && $entreprise->getGemsuiteIdentifier()) {
                        $bannerUrl = sprintf(
                            'https://app.gem-books.com/?layout=image&d=%s&filename=%s',
                            $entreprise->getGemsuiteIdentifier(),
                            $companyData['website_banner']
                        );
                        $homeSlider->setImage($bannerUrl);
                    } else {
                        $homeSlider->setImage('');
                    }
                    $tenantEm->persist($homeSlider);
                }

                $user = new User();
                $user->setFirstname($dto->adminName);
                $user->setLastname('');
                $user->setEmail($dto->adminEmail);
                $user->setUsername($dto->adminEmail);
                $user->setRoles(['ROLE_ADMIN']);
                $user->setPassword($passwordHasher->hashPassword($user, $dto->plainPassword));
                $user->setIsVerified(true); 

                $tenantEm->persist($user);
                
                $tenantEm->flush();
                $this->addFlash('info', 'Profil de l\'entreprise et administrateur créés.');

            } catch (\Throwable $e) {
                $this->addFlash('warning', 'Erreur lors de la création du profil de l\'entreprise et administrateur.');
            }
            
            if ($dto->gemsuiteToken) {
                try {
                    $gemsuiteImporter->importDataForTenant($dto->code, $dto->gemsuiteToken);
                    $this->addFlash('info', 'Les produits de GEM-SUITE ont été importés.');
                } catch (\Throwable $e) {
                    $this->addFlash('warning', 'Le site a été créé, mais l\'importation des produits a échoué: ' . $e->getMessage());
                }
            }

            $this->addFlash('success', 'Le site pour ' . $dto->companyName . ' est prêt !');
            return $this->redirectToRoute('app_home');
        }

        return $this->render('tenant_setup/form.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
