<?php
namespace App\Controller\TenantSetupController;

use App\Dto\TenantSetupDTO;
use App\Entity\Entreprise;
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

class TenantSetupController extends AbstractController
{
    #[Route('/setup/new-store', name: 'app_tenant_setup')]
    public function setup(
        Request $request,
        TenantConnectionManager $tenantManager,
        TenantEntityManagerProvider $emProvider,
        UserPasswordHasherInterface $passwordHasher,
        SluggerInterface $slugger,
        GemsuiteImporter $gemsuiteImporter
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

                $entreprise = new Entreprise();
                $entreprise->setName($dto->companyName);
                $entreprise->setEmail($dto->companyEmail);
                $entreprise->setTvaIntracommunautaire($dto->companyTva);
                $entreprise->setEin($dto->companyEin);
                
                $logoFile = $form->get('companyLogo')->getData();
                if ($logoFile) {
                    $originalFilename = pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = $slugger->slug($originalFilename);
                    $newFilename = $safeFilename.'-'.uniqid().'.'.$logoFile->guessExtension();
                    try {
                        $logoFile->move($this->getParameter('kernel.project_dir').'/public/assets/uploads/email-logos', $newFilename);
                        $entreprise->setLogo('assets/uploads/email-logos/' . $newFilename);
                    } catch (FileException $e) {
                        $this->addFlash('warning', 'Le logo n\'a pas pu être uploadé : ' . $e->getMessage());
                    }
                }
                $tenantEm->persist($entreprise);

                // Création de l'utilisateur admin
                $user = new User();
                $user->setFirstname($dto->adminName);
                $user->setLastname('');
                $user->setEmail($dto->adminEmail);
                
                // *** LIGNE AJOUTÉE POUR CORRIGER L'ERREUR ***
                $user->setUsername($dto->adminEmail);

                $user->setRoles(['ROLE_ADMIN']);
                $user->setPassword($passwordHasher->hashPassword($user, $dto->plainPassword));
                $user->setIsVerified(true); 

                $tenantEm->persist($user);
                
                $tenantEm->flush();
                $this->addFlash('info', 'Profil de l\'entreprise et administrateur créés.');

            } catch (\Throwable $e) {
                // Si la création des données échoue, on supprime le tenant pour ne pas le laisser dans un état instable.
                // Vous pouvez commenter cette partie si vous préférez un rollback manuel.
                try {
                    $tenantManager->deleteTenant($dto->code, $dbname);
                    $this->addFlash('warning', 'Le tenant a été supprimé suite à une erreur de configuration.');
                } catch (\Throwable $deleteEx) {
                    $this->addFlash('error', 'Erreur critique : impossible de supprimer le tenant après l\'échec de la configuration.');
                }

                $this->addFlash('error', 'Erreur lors de la configuration des données initiales : ' . $e->getMessage());
                return $this->redirectToRoute('app_tenant_setup');
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