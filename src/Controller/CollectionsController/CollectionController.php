<?php

namespace App\Controller\CollectionsController;

use App\Entity\Collections;
use App\Entity\User; // Import de la classe User
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\JsonResponse;


class CollectionController extends AbstractController
{
    private $entityManager;
    private $security;

    public function __construct(EntityManagerInterface $entityManager, Security $security)
    {
        $this->entityManager = $entityManager;
        $this->security = $security;
    }

    #[Route('/api/createcollections', name: 'create_collection', methods: ['POST'])]
    public function createCollection(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);

        // Utilisez dd() pour arrêter l'exécution et afficher les données
        // dd($data);

        // Récupérer l'utilisateur connecté
        $currentUser = $this->security->getUser();
           
        if (!$currentUser) {
            return $this->json(['error' => 'Unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Récupérer l'utilisateur par ID
        if (!isset($data['userId']) || $data['userId'] !== $currentUser->getId()) {
            return $this->json(['error' => 'You can only create collections for yourself'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->entityManager->getRepository(User::class)->find($data['userId']);
        if (!$user) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }
        // dd($user);
        $collection = new Collections();
        $collection->setBudgetCollection($data['budgetCollection']);
        $collection->setStartDateCollection(new \DateTime($data['startDateCollection']));
        $collection->setEndDateCollection(new \DateTime($data['endDateCollection']));
        $collection->setDel($data['del']);
        $collection->setNomCollection($data['nomCollection']);
        $collection->setPhotoCollection($data['photoCollection']);
        $collection->setUserCollections($user);

        $this->entityManager->persist($collection);
        $this->entityManager->flush();

        return $this->json($collection, Response::HTTP_CREATED, [], ['groups' => 'collection:read']);
    }

    #[Route('/api/collections', name: 'get_collections', methods: ['GET'])]
    public function getCollections(): JsonResponse
    {
        // Récupération de toutes les collections
        $collections = $this->entityManager->getRepository(Collections::class)->findAll();
    
        // Transformation des collections en tableau associatif pour les convertir en JSON
        $collectionsArray = [];
        foreach ($collections as $collection) {
            $user = $collection->getUserCollections(); // Récupération de l'utilisateur associé
            $collectionsArray[] = [
                'id' => $collection->getId(),
                'budgetCollection' => $collection->getBudgetCollection(),
                'startDateCollection' => $collection->getStartDateCollection()->format('Y-m-d H:i:s'),
                'endDateCollection' => $collection->getEndDateCollection()->format('Y-m-d H:i:s'),
                'del' => $collection->isDel(),
                'nomCollection' => $collection->getNomCollection(),
                'photoCollection' => $collection->getPhotoCollection(),
                'user' => $user ? [
                    'id' => $user->getId(),
                    'name' => $user->getFirstName() . ' ' . $user->getLastName(),
                    'email' => $user->getEmail(),
                ] : null,
            ];
        }
    
        return $this->json($collectionsArray, 200);
    }
    
    
    #[Route('/api/collections/{id}', name: 'delete_collection', methods: ['DELETE'])]
    public function deleteCollection(Collections $collection): JsonResponse
    {
        // Suppression de la collection
        $this->entityManager->remove($collection);
        $this->entityManager->flush();
    
        return $this->json(['message' => 'Collection deleted successfully'], 200);
    }
    
    
}
