<?php
namespace App\Services\ContactService;

use App\Dto\ContactInputDto;
use App\Entity\Contact;
use App\Services\TenantEntityManagerProvider;

class ContactService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllContacts(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Contact::class)->findBy([], ['createdAt' => 'DESC']);
    }

    public function findContact(int $id): ?Contact
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Contact::class)->find($id);
    }

    public function createContact(ContactInputDto $dto): Contact
    {
        $tenantEm = $this->emProvider->getEntityManager();
        
        $contact = new Contact();
        $contact->setName($dto->name);
        $contact->setEmail($dto->email);
        $contact->setPhone($dto->phone);
        $contact->setSubject($dto->subject);
        $contact->setContent($dto->Content);
        $contact->setIsRead($dto->isRead);
        
        $tenantEm->persist($contact);
        $tenantEm->flush();

        return $contact;
    }

    public function updateContact(Contact $contact, ContactInputDto $dto): Contact
    {
        $tenantEm = $this->emProvider->getEntityManager();

        $contact->setName($dto->name ?? $contact->getName());
        $contact->setEmail($dto->email ?? $contact->getEmail());
        $contact->setPhone($dto->phone ?? $contact->getPhone());
        $contact->setSubject($dto->subject ?? $contact->getSubject());
        $contact->setContent($dto->Content ?? $contact->getContent());
        $contact->setIsRead($dto->isRead ?? $contact->isIsRead());
        
        $tenantEm->flush();

        return $contact;
    }

    public function deleteContact(Contact $contact): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->remove($contact);
        $tenantEm->flush();
    }
}