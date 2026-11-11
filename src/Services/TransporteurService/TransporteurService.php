<?php
namespace App\Services\TransporteurService;

use App\Entity\Transporteur;
use App\Services\TenantEntityManagerProvider; 

class TransporteurService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllTransporteurs(): array
    {
        // MODIFICATION 2 : On récupère l'EM du tenant ici
        $em = $this->emProvider->getEntityManager();
        return $em->getRepository(Transporteur::class)->findAll();
    }

    public function createTransporteur(string $name, ?string $logo, ?string $contact): Transporteur
    {
        $em = $this->emProvider->getEntityManager();

        $transporteur = new Transporteur();
        $transporteur->setName($name);
        $transporteur->setLogo($logo);
        $transporteur->setContact($contact);

        $em->persist($transporteur);
        $em->flush();

        return $transporteur;
    }

    public function deleteTransporteur(Transporteur $transporteur): void
    {
        $em = $this->emProvider->getEntityManager();
        // lot G4 (09/11/2026) : refusé s'il est utilisé par un frais de port ou une statistique figée (avant : violation de clé étrangère)
        if (!$transporteur->getFraisDePorts()->isEmpty() || !$transporteur->getCommandeStatistiques()->isEmpty()) {
            throw new \App\Gestion\Services\GestionConflictException('TRANSPORTEUR_IN_USE', 'Ce transporteur est utilisé par des frais de port : il ne peut pas être supprimé.');
        }
        $em->remove($transporteur);
        $em->flush();
    }
}