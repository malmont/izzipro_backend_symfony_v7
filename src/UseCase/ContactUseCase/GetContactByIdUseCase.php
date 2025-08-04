<?php

namespace App\UseCase\ContactUseCase;

use App\Entity\Contact;
use App\Services\ContactService\ContactService; 

class GetContactByIdUseCase
{
    public function __construct(
        private ContactService $contactService 
    ) {
    }


    public function execute(int $id): ?Contact
    {
        return $this->contactService->findContact($id);
    }
}
