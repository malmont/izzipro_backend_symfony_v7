<?php
namespace App\Controller\CommandeController;

use App\Entity\Collections;
use App\UseCase\CommandeUseCase\GetCommandesByCollectionUseCase;
use App\UseCase\CommandeUseCase\CreateCommandeUseCase;
use App\Dto\CommandeOutputDTO;
use App\Dto\FournisseurInputDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;

class CommandeController extends AbstractController
{
    private $getCommandesByCollectionUseCase;
    private $createCommandeUseCase;
    private $security;

    public function __construct(
        GetCommandesByCollectionUseCase $getCommandesByCollectionUseCase,
        CreateCommandeUseCase $createCommandeUseCase,
        Security $security
    ) {
        $this->getCommandesByCollectionUseCase = $getCommandesByCollectionUseCase;
        $this->createCommandeUseCase = $createCommandeUseCase;
        $this->security = $security;
    }

    #[Route('/api/collections/{id}/commandes', name: 'get_commandes_by_collection', methods: ['GET'])]
    public function getCommandesByCollection(Collections $collection): JsonResponse
    {
        $commandes = $this->getCommandesByCollectionUseCase->execute($collection);
    
        // Utiliser toArray() pour convertir la collection Doctrine en tableau PHP
        $commandesArray = $commandes->toArray();
    
        // Transformer chaque commande en CommandeOutputDTO
        $commandesDTO = array_map(function($commande) {
            return new CommandeOutputDTO($commande);
        }, $commandesArray);
    
        return $this->json($commandesDTO, 200);
    }
    #[Route('/api/collections/{id}/commandes', name: 'create_commande', methods: ['POST'])]
    public function createCommande(Request $request, Collections $collection): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $currentUser = $this->security->getUser();

        if (!$currentUser) {
            return $this->json(['error' => 'Unauthenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $fournisseurDTO = new FournisseurInputDTO($data['fournisseur'] ?? []);
        
        $this->createCommandeUseCase->execute($data, $collection, $fournisseurDTO);

        return $this->json(['success' => 'Commande created'], JsonResponse::HTTP_CREATED);
    }
}
