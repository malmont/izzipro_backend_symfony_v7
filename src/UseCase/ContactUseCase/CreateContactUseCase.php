<?php
namespace App\UseCase\ContactUseCase;

use App\Dto\ContactInputDto;
use App\Entity\Contact;
use App\Services\ContactService\ContactService;

class CreateContactUseCase
{
    private ContactService $contactService;
    public function __construct(ContactService $contactService) { $this->contactService = $contactService; }
    public function execute(ContactInputDto $dto): Contact { return $this->contactService->createContact($dto); }
}
