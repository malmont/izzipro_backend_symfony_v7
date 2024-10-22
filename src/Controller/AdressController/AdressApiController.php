<?php
namespace App\Controller\AdressController;

use App\Dto\AdressInputDTO;
use App\Entity\Adress;
use App\UseCase\AdressUseCase\GetUserAdressesUseCase;
use App\UseCase\AdressUseCase\CreateAdressUseCase;
use App\UseCase\AdressUseCase\EditAdressUseCase;
use App\UseCase\AdressUseCase\DeleteAdressUseCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/adresses')]
class AdressApiController extends AbstractController
{
    private GetUserAdressesUseCase $getUserAdressesUseCase;
    private CreateAdressUseCase $createAdressUseCase;
    private EditAdressUseCase $editAdressUseCase;
    private DeleteAdressUseCase $deleteAdressUseCase;

    public function __construct(
        GetUserAdressesUseCase $getUserAdressesUseCase,
        CreateAdressUseCase $createAdressUseCase,
        EditAdressUseCase $editAdressUseCase,
        DeleteAdressUseCase $deleteAdressUseCase
    ) {
        $this->getUserAdressesUseCase = $getUserAdressesUseCase;
        $this->createAdressUseCase = $createAdressUseCase;
        $this->editAdressUseCase = $editAdressUseCase;
        $this->deleteAdressUseCase = $deleteAdressUseCase;
    }

    #[Route('/', name: 'get_user_adresses', methods: ['GET'])]
    public function getUserAdresses(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_UNAUTHORIZED);
        }

        $adresses = $this->getUserAdressesUseCase->execute($user);

        return $this->json($adresses, Response::HTTP_OK);
    }

    #[Route('', name: 'create_adress', methods: ['POST'])]
    public function createAdress(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $inputDTO = new AdressInputDTO($data);
        
        $adress = $this->createAdressUseCase->execute($inputDTO, $user);

        return $this->json(['success' => 'Adresse créée avec succès'], Response::HTTP_CREATED);

    }

    #[Route('/{id}', name: 'edit_adress', methods: ['PUT'])]
        public function editAdress(Request $request, Adress $adress): JsonResponse
        {
            $user = $this->getUser();

            // Vérifier que l'utilisateur est authentifié et qu'il est bien propriétaire de l'adresse
            if (!$user || $adress->getUserAdress() !== $user) {
                return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            $data = json_decode($request->getContent(), true);

            if (!$data) {
                return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
            }

            $inputDTO = new AdressInputDTO($data);
            $this->editAdressUseCase->execute($inputDTO, $adress);

            return $this->json(['success' => 'Adresse mise à jour avec succès'], Response::HTTP_OK);
        }


    #[Route('/{id}', name: 'delete_adress', methods: ['DELETE'])]
    public function deleteAdress(Adress $adress): JsonResponse
    {
        $user = $this->getUser();

        if (!$user || $adress->getUserAdress() !== $user) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $this->deleteAdressUseCase->execute($adress);

        return new JsonResponse(['success' => 'Address deleted successfully'], Response::HTTP_NO_CONTENT);
    }
}
