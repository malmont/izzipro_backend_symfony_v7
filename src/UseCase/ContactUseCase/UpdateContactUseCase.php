<?php
namespace App\UseCase\ContactUseCase;

use App\Dto\ContactInputDto;
use App\Entity\Contact;
use App\Services\ContactService\ContactService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateContactUseCase
{
    private ContactService $contactService;
    public function __construct(ContactService $contactService) { $this->contactService = $contactService; }
    public function execute(int $id, ContactInputDto $dto): Contact
    {
        $contact = $this->contactService->findContact($id);
        if (!$contact) { throw new NotFoundHttpException('Contact non trouvé.'); }
        return $this->contactService->updateContact($contact, $dto);
    }
}
