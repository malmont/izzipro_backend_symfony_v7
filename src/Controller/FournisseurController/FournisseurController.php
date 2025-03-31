<?php
namespace App\Controller\FournisseurController;

use App\Dto\FournisseurInputDTO;
use App\Dto\FournisseurOutputDTO;
use App\UseCase\FournisseurUseCase\CreateFournisseurUseCase;
use App\UseCase\FournisseurUseCase\GetAllFournisseursUseCase;
use App\UseCase\FournisseurUseCase\DeleteFournisseurUseCase;
use App\Entity\Fournisseur;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class FournisseurController extends AbstractController
{
    private GetAllFournisseursUseCase $getAllFournisseursUseCase;
    private CreateFournisseurUseCase $createFournisseurUseCase;
    private DeleteFournisseurUseCase $deleteFournisseurUseCase;

    public function __construct(
        GetAllFournisseursUseCase $getAllFournisseursUseCase,
        CreateFournisseurUseCase $createFournisseurUseCase,
        DeleteFournisseurUseCase $deleteFournisseurUseCase
    ) {
        $this->getAllFournisseursUseCase = $getAllFournisseursUseCase;
        $this->createFournisseurUseCase = $createFournisseurUseCase;
        $this->deleteFournisseurUseCase = $deleteFournisseurUseCase;
    }

    #[Route('/api/fournisseurs', name: 'get_all_fournisseurs', methods: ['GET'])]
        public function getAllFournisseurs(Request $request): JsonResponse
        {
            $host = $request->getSchemeAndHttpHost();
            $fournisseurs = $this->getAllFournisseursUseCase->execute();

            $fournisseursArray = array_map(function($fournisseur) use ($host) {
                return new FournisseurOutputDTO($fournisseur, $host);
            }, $fournisseurs);

            return $this->json($fournisseursArray, JsonResponse::HTTP_OK);
        }

    #[Route('/api/fournisseurs', name: 'create_fournisseur', methods: ['POST'])]
    public function createFournisseur(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $fournisseurInputDTO = new FournisseurInputDTO($data);
        $fournisseur = $this->createFournisseurUseCase->execute($fournisseurInputDTO);

        return $this->json(['success' => 'Fournisseur créé avec succès', 'fournisseur_id' => $fournisseur->getId()], JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/fournisseurs/{id}', name: 'delete_fournisseur', methods: ['DELETE'])]
    public function deleteFournisseur(Fournisseur $fournisseur): JsonResponse
    {
        $this->deleteFournisseurUseCase->execute($fournisseur);

        return $this->json(['success' => 'Fournisseur supprimé avec succès'], JsonResponse::HTTP_NO_CONTENT);
    }
}

