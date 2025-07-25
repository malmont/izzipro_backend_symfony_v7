<?php
namespace App\UseCase\ContactUseCase;

use App\Services\ContactService\ContactService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteContactUseCase
{
    private ContactService $contactService;
    public function __construct(ContactService $contactService) { $this->contactService = $contactService; }
    public function execute(int $id): void
    {
        $contact = $this->contactService->findContact($id);
        if (!$contact) { throw new NotFoundHttpException('Contact non trouvé.'); }
        $this->contactService->deleteContact($contact);
    }
}