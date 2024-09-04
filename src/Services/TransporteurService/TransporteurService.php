<?php
namespace App\Services\TransporteurService;


use App\Entity\Transporteur;
use Doctrine\ORM\EntityManagerInterface;

class TransporteurService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getAllTransporteurs(): array
    {
        return $this->entityManager->getRepository(Transporteur::class)->findAll();
    }

    public function createTransporteur(string $name, ?string $logo, ?string $contact): Transporteur
    {
        $transporteur = new Transporteur();
        $transporteur->setName($name);
        $transporteur->setLogo($logo);
        $transporteur->setContact($contact);

        $this->entityManager->persist($transporteur);
        $this->entityManager->flush();

        return $transporteur;
    }

    public function deleteTransporteur(Transporteur $transporteur): void
    {
        $this->entityManager->remove($transporteur);
        $this->entityManager->flush();
    }
}
