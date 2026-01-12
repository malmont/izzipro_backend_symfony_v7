<?php

namespace App\Controller\AdressController;

use App\Dto\AdressInputDTO;
use App\Entity\Adress;
use App\Entity\User;
use App\UseCase\AdressUseCase\GetUserAdressesUseCase;
use App\UseCase\AdressUseCase\CreateAdressUseCase;
use App\UseCase\AdressUseCase\EditAdressUseCase;
use App\UseCase\AdressUseCase\DeleteAdressUseCase;
use App\Services\AdressService\AddressVerificationService;
use App\Services\GemsuiteImporterService\GemsuiteClientUpdater;
use App\Services\TenantCacheService;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/api/adresses')]
class AdressApiController extends AbstractController
{
    private GetUserAdressesUseCase $getUserAdressesUseCase;
    private CreateAdressUseCase $createAdressUseCase;
    private EditAdressUseCase $editAdressUseCase;
    private DeleteAdressUseCase $deleteAdressUseCase;
    private AddressVerificationService $verifier;
    private TenantCacheService $cache;
    private GemsuiteClientUpdater $gemsuiteUpdater;

    public function __construct(
        GetUserAdressesUseCase $getUserAdressesUseCase,
        CreateAdressUseCase $createAdressUseCase,
        EditAdressUseCase $editAdressUseCase,
        DeleteAdressUseCase $deleteAdressUseCase,
        AddressVerificationService $verifier,
        TenantCacheService $cache,
        GemsuiteClientUpdater $gemsuiteUpdater
    ) {
        $this->getUserAdressesUseCase = $getUserAdressesUseCase;
        $this->createAdressUseCase = $createAdressUseCase;
        $this->editAdressUseCase = $editAdressUseCase;
        $this->deleteAdressUseCase = $deleteAdressUseCase;
        $this->verifier = $verifier;
        $this->cache = $cache;
        $this->gemsuiteUpdater = $gemsuiteUpdater;
    }

    /**
     * Récupérer les adresses de l'utilisateur
     */
    #[Route('/', name: 'get_user_adresses', methods: ['GET'])]
    public function getUserAdresses(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_UNAUTHORIZED);
        }

        $cacheKey = "adresses_user_" . $user->getId();
        $adresses = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($user) {
                $item->expiresAfter(3600);
                $item->tag(['adresses_user']);
                return $this->getUserAdressesUseCase->execute($user);
            },
            /* ttl */
            3600,
            /* extraTags */
            ['adresses_user']
        );

        return $this->json($adresses, Response::HTTP_OK);
    }

    /**
     * Créer une nouvelle adresse
     */
    #[Route('', name: 'create_adress', methods: ['POST'])]
    public function createAdress(Request $request, ValidatorInterface $validator, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not found'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $dto  = new AdressInputDTO($data);

        // Validation DTO
        $violations = $validator->validate($dto);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[$v->getPropertyPath()] = $v->getMessage();
            }
            return $this->json(['error' => $errors], Response::HTTP_BAD_REQUEST);
        }

        // Vérification & normalisation via EasyPost
        try {
            $normalized = $this->verifier->verify([
                'street1'     => $dto->addressLineOne,
                'street2'     => $dto->addressLineTwo,
                'city'        => $dto->city,
                'province'    => $dto->province,
                'postal_code' => $dto->zipCode,
                'country'     => $dto->country,
            ]);
        } catch (\RuntimeException $e) {
            return $this->json(
                ['error' => ['address' => $e->getMessage()]],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Injecter la version normalisée
        $dto->addressLineOne = $normalized['street1'];
        $dto->addressLineTwo = $normalized['street2'];
        $dto->city           = $normalized['city'];
        $dto->province       = $normalized['province'];
        $dto->zipCode        = $normalized['postal_code'];
        $dto->country        = $normalized['country'];

        $adress = $this->createAdressUseCase->execute($dto, $user);
        if ($dto->isPrimary) {
            $user->setPrimaryAddress($adress);
            $this->gemsuiteUpdater->syncAddress($user, $adress);
        }
        $em->flush();
        return $this->json(['success' => 'Adresse créée avec succès'], Response::HTTP_CREATED);
    }

    /**
     * Modifier une adresse
     */
    #[Route('/{id}', name: 'edit_adress', methods: ['PUT'])]
    public function editAdress(Request $request, ValidatorInterface $validator, Adress $adress, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user || $adress->getUserAdress() !== $user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $dto  = new AdressInputDTO($data);

        // Validation DTO
        $violations = $validator->validate($dto);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[$v->getPropertyPath()] = $v->getMessage();
            }
            return $this->json(['error' => $errors], Response::HTTP_BAD_REQUEST);
        }

        // Vérification & normalisation via EasyPost
        try {
            $normalized = $this->verifier->verify([
                'street1'     => $dto->addressLineOne,
                'street2'     => $dto->addressLineTwo,
                'city'        => $dto->city,
                'province'    => $dto->province,
                'postal_code' => $dto->zipCode,
                'country'     => $dto->country,
            ]);
        } catch (\RuntimeException $e) {
            return $this->json(
                ['error' => ['address' => $e->getMessage()]],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Injecter la version normalisée
        $dto->addressLineOne = $normalized['street1'];
        $dto->addressLineTwo = $normalized['street2'];
        $dto->city           = $normalized['city'];
        $dto->province       = $normalized['province'];
        $dto->zipCode        = $normalized['postal_code'];
        $dto->country        = $normalized['country'];

        $this->editAdressUseCase->execute($dto, $adress);
        if ($dto->isPrimary) {
            $user->setPrimaryAddress($adress);
            $this->gemsuiteUpdater->syncAddress($user, $adress);
        } elseif ($user->getPrimaryAddress() === $adress) {
            $user->setPrimaryAddress(null);
        }

        $em->flush();
        return $this->json(['success' => 'Adresse mise à jour avec succès'], Response::HTTP_OK);
    }

    /**
     * Définir une adresse existante comme adresse principale
     */
    #[Route('/{id}/set-primary', name: 'set_primary_adress', methods: ['PUT'])]
    public function setPrimaryAddress(Adress $adress, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user || $adress->getUserAdress() !== $user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $user->setPrimaryAddress($adress);
        $em->persist($user);
        $em->flush();

        $this->gemsuiteUpdater->syncAddress($user, $adress);

        return $this->json(['success' => 'Adresse principale mise à jour avec succès']);
    }

    /**
     * Supprimer une adresse
     */
    #[Route('/{id}', name: 'delete_adress', methods: ['DELETE'])]
    public function deleteAdress(Adress $adress): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user || $adress->getUserAdress() !== $user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $this->deleteAdressUseCase->execute($adress);
        return $this->json(['success' => 'Adresse supprimée avec succès'], Response::HTTP_NO_CONTENT);
    }
}
