<?php
namespace App\Controller\CommandeController;


use App\Entity\Collections;
use App\Entity\Commande;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;

class CommandeController extends AbstractController
{
    private $entityManager;
    private $security;

    public function __construct(EntityManagerInterface $entityManager, Security $security)
    {
        $this->entityManager = $entityManager;
        $this->security = $security;
    }

    #[Route('/api/collections/{id}/commandes', name: 'get_commandes_by_collection', methods: ['GET'])]
    public function getCommandesByCollection(Collections $collection): JsonResponse
    {
        $commandes = $this->entityManager->getRepository(Collections::class)
            ->find($collection->getId())
            ->getCollectionCommande();

        // Pour s'assurer que les commandes sont initialisées
        $commandes = $commandes->getValues();

        // Convertir les objets de commande en tableau pour une meilleure lisibilité
        $commandesArray = [];
        foreach ($commandes as $commande) {
            $commandesArray[] = [
                'id' => $commande->getId(),
                'budget' => $commande->getBudget(),
                'date' => $commande->getDate()->format('Y-m-d H:i:s'),
                'name' => $commande->getName(),
                'photo' => $commande->getPhoto(),
                'collectionId' => $commande->getCollections()->getId(),
            ];
        }

        return $this->json($commandesArray, 200);
    }

    #[Route('/api/collections/{id}/commandes', name: 'create_commande', methods: ['POST'])]
    public function createCommande(Request $request, Collections $collection): JsonResponse
    {
       
        $data = json_decode($request->getContent(), true);
        // dd($data);
        $currentUser = $this->security->getUser();
        if (!$currentUser) {
            return $this->json(['error' => 'Unauthenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $commande = new Commande();
        $commande->setBudget($data['budget']);
        $commande->setDate(new \DateTime($data['date']));
        $commande->setName($data['name']);
        $commande->setPhoto($data['photo']);
        $commande->setCollections($collection);

        $this->entityManager->persist($commande);
        $this->entityManager->flush();

        return $this->json($commande, JsonResponse::HTTP_CREATED, [], ['groups' => 'commande:read']);
    }
}