<?php
namespace App\UseCase\ContactUseCase;

use App\Services\ContactService\ContactService;

class GetAllContactsUseCase
{
    private ContactService $contactService;
    public function __construct(ContactService $contactService) { $this->contactService = $contactService; }
    public function execute(): array { return $this->contactService->getAllContacts(); }
    /** Lot PERF-PAGINATION @return array{items: list<\App\Entity\Contact>, total: int} */
    public function executePage(int $offset, int $limit): array { return $this->contactService->getContactsPage($offset, $limit); }
}