<?php
namespace App\Services\FraisDePortService;

use App\Entity\Commande;
use App\Entity\FraisDePort;
use App\Entity\Transporteur;
use App\Dto\FraisDePortInputDTO;
use Doctrine\ORM\EntityManagerInterface;

class FraisDePortService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getFraisDePortByCommande(Commande $commande): ?FraisDePort
    {
        return $commande->getFraisDePort();
    }

    public function createFraisDePort(Commande $commande, FraisDePortInputDTO $inputDTO): void
    {
        $fraisDePort = new FraisDePort();
        $fraisDePort->setName($inputDTO->name);
        $fraisDePort->setFacture($inputDTO->facture);
        $fraisDePort->setTracknumber($inputDTO->tracknumber);
        $fraisDePort->setPrice($inputDTO->price);
        $fraisDePort->setCommande($commande);

        $transporteur = $this->entityManager->getRepository(Transporteur::class)->find($inputDTO->transporteurId);
        if ($transporteur) {
            $fraisDePort->setTransporteur($transporteur);
        }

        $this->entityManager->persist($fraisDePort);
        $this->entityManager->flush();
    }

    public function deleteFraisDePort(Commande $commande): void
    {
        $fraisDePort = $commande->getFraisDePort();

        if ($fraisDePort) {
            $this->entityManager->remove($fraisDePort);
            $this->entityManager->flush();
        }
    }
}
