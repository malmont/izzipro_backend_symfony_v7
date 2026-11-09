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

    public function __construct(TenantEntityManagerProvider $emProvider, private readonly ?\App\Services\GestionService\PeriodGuard $periodGuard = null)
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

    public function createFraisDePort(Commande $commande, FraisDePortInputDTO $inputDTO): FraisDePort
    {
        $em = $this->emProvider->getEntityManager();
        // lot G4 (09/11/2026) : un seul frais de port par commande (409), commande fermée (409), transporteur inconnu (404)
        $this->periodGuard?->assertCommandeWritable($commande); // figée ou datée dans une période fermée (lot P1, 10/11/2026)
        if ($commande->getIsClosed()) {
            throw new \App\Services\GestionService\GestionConflictException('COMMANDE_CLOSED', 'La commande est figée.');
        }
        if ($commande->getFraisDePort()) {
            throw new \App\Services\GestionService\GestionConflictException('SHIPPING_COST_ALREADY_SET', 'La commande a déjà un frais de port : supprimez-le avant d\'en créer un autre.');
        }

        $fraisDePort = new FraisDePort();
        $fraisDePort->setName($inputDTO->name);
        $fraisDePort->setFacture($inputDTO->facture);
        $fraisDePort->setTracknumber($inputDTO->tracknumber);
        $fraisDePort->setPrice($inputDTO->price);
        $fraisDePort->setCommande($commande);

        // On récupère le repository depuis l'EM du tenant
        $transporteurRepository = $em->getRepository(Transporteur::class);
        $transporteur = $transporteurRepository->find($inputDTO->transporteurId);
        if (!$transporteur) {
            throw new \App\Services\GestionService\GestionConflictException('TRANSPORTEUR_NOT_FOUND', 'Transporteur inconnu : ' . $inputDTO->transporteurId, 404);
        }
        $fraisDePort->setTransporteur($transporteur);
        $em->persist($fraisDePort);
        $em->flush();

        return $fraisDePort;
    }

    public function deleteFraisDePort(Commande $commande): void
    {
        $this->periodGuard?->assertCommandeWritable($commande); // lot P1 (10/11/2026)
        $fraisDePort = $commande->getFraisDePort();

        if ($fraisDePort) {
            $em = $this->emProvider->getEntityManager();
            $em->remove($fraisDePort);
            
            $em->flush();
        }
    }
}