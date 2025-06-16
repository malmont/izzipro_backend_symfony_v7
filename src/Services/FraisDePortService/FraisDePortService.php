<?php
namespace App\Services\FraisDePortService;

use App\Entity\Commande;
use App\Entity\FraisDePort;
use App\Entity\Transporteur;
use App\Dto\FraisDePortInputDTO;
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider

class FraisDePortService
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * INCHANGÉ : Cette méthode ne touche pas à la base de données.
     * Elle lit simplement une propriété d'un objet déjà chargé.
     * Elle n'a donc pas besoin d'être modifiée.
     */
    public function getFraisDePortByCommande(Commande $commande): ?FraisDePort
    {
        return $commande->getFraisDePort();
    }

    public function createFraisDePort(Commande $commande, FraisDePortInputDTO $inputDTO): void
    {
        // MODIFICATION 2 : On récupère l'EM du tenant ici
        $em = $this->emProvider->getEntityManager();

        $fraisDePort = new FraisDePort();
        $fraisDePort->setName($inputDTO->name);
        $fraisDePort->setFacture($inputDTO->facture);
        $fraisDePort->setTracknumber($inputDTO->tracknumber);
        $fraisDePort->setPrice($inputDTO->price);
        $fraisDePort->setCommande($commande);

        // On récupère le repository depuis l'EM du tenant
        $transporteurRepository = $em->getRepository(Transporteur::class);
        $transporteur = $transporteurRepository->find($inputDTO->transporteurId);
        if ($transporteur) {
            $fraisDePort->setTransporteur($transporteur);
        }
        $em->persist($fraisDePort);
        $em->flush();
    }

    public function deleteFraisDePort(Commande $commande): void
    {
        $fraisDePort = $commande->getFraisDePort();

        if ($fraisDePort) {
            $em = $this->emProvider->getEntityManager();
            $em->remove($fraisDePort);
            
            $em->flush();
        }
    }
}