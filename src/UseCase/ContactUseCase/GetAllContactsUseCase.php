<?php
namespace App\UseCase\ContactUseCase;

use App\Services\ContactService\ContactService;

class GetAllContactsUseCase
{
    private ContactService $contactService;
    public function __construct(ContactService $contactService) { $this->contactService = $contactService; }
    public function execute(): array { return $this->contactService->getAllContacts(); }
}