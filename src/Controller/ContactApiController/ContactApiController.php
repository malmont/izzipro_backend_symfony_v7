<?php
namespace App\Controller\ContactApiController;

use App\Dto\ContactInputDto;
use App\Dto\ContactOutputDto;
use App\Services\TenantCacheService;
use App\UseCase\ContactUseCase\GetAllContactsUseCase;
use App\UseCase\ContactUseCase\CreateContactUseCase;
use App\UseCase\ContactUseCase\UpdateContactUseCase;
use App\UseCase\ContactUseCase\DeleteContactUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;
use App\UseCase\ContactUseCase\GetContactByIdUseCase;

#[Route('/api/contacts')]
class ContactApiController extends AbstractController
{
    public function __construct(
        private GetAllContactsUseCase $getAllContactsUseCase,
        private CreateContactUseCase $createContactUseCase,
        private UpdateContactUseCase $updateContactUseCase,
        private DeleteContactUseCase $deleteContactUseCase,
        private TenantCacheService $cache,
        private GetContactByIdUseCase $getContactByIdUseCase,
        private ?\App\Services\ContactService\ContactMailerService $contactMailerService = null
    ) {}

    #[Route('', name: 'api_contact_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $cacheKey = 'contacts_all';
        $cacheTags = ['contacts'];

        $contactsDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) {
                $contacts = $this->getAllContactsUseCase->execute();
                return array_map(fn($contact) => new ContactOutputDto($contact), $contacts);
            },
            3600,
            $cacheTags
        );

        return $this->json($contactsDto);
    }

    #[Route('/{id}', name: 'api_contact_get_one', methods: ['GET'])]
    public function getOne(int $id): JsonResponse
    {
        $contact = $this->getContactByIdUseCase->execute($id);

        if (!$contact) {
            return $this->json(['message' => 'Contact non trouvé'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(new ContactOutputDto($contact));
    }

    #[Route('', name: 'api_contact_create', methods: ['POST'])]
    #[Route('/create', name: 'api_contact_create_alias', methods: ['POST'])]
    public function create(Request $request, #[MapRequestPayload] ContactInputDto $dto): JsonResponse
    {
        $contact = $this->createContactUseCase->execute($dto);

        if ($this->contactMailerService) {
            $locale = $request->getLocale() ?: 'fr';
            $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';
            $this->contactMailerService->sendAdminNotification($contact, $locale, $domain);
            $this->contactMailerService->sendCustomerConfirmation($contact, $locale, $domain);
        }

        return $this->json(new ContactOutputDto($contact), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_contact_update', methods: ['PUT'])]
    public function update(int $id, #[MapRequestPayload] ContactInputDto $dto): JsonResponse
    {
        $contact = $this->updateContactUseCase->execute($id, $dto);
        return $this->json(new ContactOutputDto($contact));
    }

    #[Route('/{id}', name: 'api_contact_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->deleteContactUseCase->execute($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}