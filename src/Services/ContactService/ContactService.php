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

    /**
     * Lot PERF-PAGINATION : une page des demandes de contact, la plus récente d'abord (date, puis numéro), lue en base.
     *
     * @return array{items: list<Contact>, total: int}
     */
    public function getContactsPage(int $offset, int $limit): array
    {
        $repository = $this->emProvider->getEntityManager()->getRepository(Contact::class);
        $items = $repository->createQueryBuilder('c')->orderBy('c.createdAt', 'DESC')->addOrderBy('c.id', 'DESC')->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => (int) $repository->count([])];
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

    /**
     * Message du formulaire public. Sans sujet, « Demande de contact – <nom de l'entreprise ou du site> ».
     */
    public function createFromForm(\App\Dto\ContactCreateInputDto $dto, string $siteName): Contact
    {
        $contact = (new Contact())
            ->setName((string) $dto->name)
            ->setEmail((string) $dto->email)
            ->setPhone($dto->phone)
            ->setSubject($dto->subject ?? mb_substr('Demande de contact – ' . $siteName, 0, 255))
            ->setContent((string) $dto->message)
            ->setIsRead(false)
            ->setIndustry($dto->industry)
            ->setCompanyName($dto->companyName)
            ->setJobFunction($dto->jobFunction);
        $em = $this->emProvider->getEntityManager();
        $em->persist($contact);
        $em->flush();

        return $contact;
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