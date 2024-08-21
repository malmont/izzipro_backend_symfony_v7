<?php
namespace App\Controller\FournisseurController;

use App\Entity\Fournisseur;
use App\Entity\Commande;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class FournisseurController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/fournisseurs', name: 'get_all_fournisseurs', methods: ['GET'])]
    public function getAllFournisseurs(): JsonResponse
    {
        $fournisseurs = $this->entityManager->getRepository(Fournisseur::class)->findAll();

        $fournisseursArray = [];
        foreach ($fournisseurs as $fournisseur) {
            $fournisseursArray[] = [
                'id' => $fournisseur->getId(),
                'name' => $fournisseur->getName(),
                'photo' => $fournisseur->getPhoto(),
                'adresse' => $fournisseur->getAdresse(),
                'ville' => $fournisseur->getVille(),
                'pays' => $fournisseur->getPays(),
                'tel' => $fournisseur->getTel(),
            ];
        }

        return $this->json($fournisseursArray, JsonResponse::HTTP_OK);
    }

    #[Route('/api/fournisseurs', name: 'create_fournisseur', methods: ['POST'])]
    public function createFournisseur(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $fournisseur = new Fournisseur();
        $fournisseur->setName($data['name'] ?? null);
        $fournisseur->setPhoto($data['photo'] ?? null);
        $fournisseur->setAdresse($data['adresse'] ?? null);
        $fournisseur->setVille($data['ville'] ?? null);
        $fournisseur->setPays($data['pays'] ?? null);
        $fournisseur->setTel($data['tel'] ?? null);

        $this->entityManager->persist($fournisseur);
        $this->entityManager->flush();

        return $this->json(['success' => 'Fournisseur créé avec succès', 'fournisseur_id' => $fournisseur->getId()], JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/fournisseurs/{id}', name: 'delete_fournisseur', methods: ['DELETE'])]
    public function deleteFournisseur(Fournisseur $fournisseur): JsonResponse
    {
        $this->entityManager->remove($fournisseur);
        $this->entityManager->flush();

        return $this->json(['success' => 'Fournisseur supprimé avec succès'], JsonResponse::HTTP_NO_CONTENT);
    }
}
