<?php
namespace App\Controller\ContactApiController;

use App\Dto\ContactInputDto;
use App\Dto\ContactOutputDto;
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
use App\UseCase\ContactUseCase\GetContactByIdUseCase;

#[Route('/api/contacts')]
class ContactApiController extends AbstractController
{
    public function __construct(
        private GetAllContactsUseCase $getAllContactsUseCase,
        private CreateContactUseCase $createContactUseCase,
        private UpdateContactUseCase $updateContactUseCase,
        private DeleteContactUseCase $deleteContactUseCase,
        private GetContactByIdUseCase $getContactByIdUseCase,
        private ?\App\Services\ContactService\ContactMailerService $contactMailerService = null,
        private ?\App\Services\MediaUrlResolver $mediaUrlResolver = null
    ) {}

    /** Liste d'administration de données personnelles : lue en base, jamais en cache (28/10/2026) */
    #[Route('', name: 'api_contact_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $contacts = $this->getAllContactsUseCase->execute();

        return $this->json(array_map(fn($contact) => new ContactOutputDto($contact), $contacts));
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

    /**
     * Formulaire de contact public. Champs : ContactCreateInputDto ; refus 422
     * { message: "Formulaire incomplet", errors: [{ field, message }] }.
     */
    #[Route('', name: 'api_contact_create', methods: ['POST'])]
    #[Route('/create', name: 'api_contact_create_alias', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $contact = $this->createContactUseCase->execute(
                json_decode($request->getContent(), true),
                (string) ($request->headers->get('X-Tenant-Host') ?: $request->getHost())
            );
        } catch (\App\UseCase\ContactUseCase\ContactFormException $e) {
            return $this->json(['message' => $e->getMessage(), 'errors' => $e->errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->contactMailerService) {
            $locale = $request->getLocale() ?: 'fr';
            $domain = ($this->mediaUrlResolver 
                ? $this->mediaUrlResolver->getEmailLogosBaseUrl($request->getSchemeAndHttpHost()) 
                : $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos') . '/';
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