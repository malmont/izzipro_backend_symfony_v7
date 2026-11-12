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

    public function __construct(TenantEntityManagerProvider $emProvider, private readonly ?\App\Gestion\Services\PeriodGuard $periodGuard = null, private readonly ?\App\Gestion\Services\Document\GestionDocumentService $documents = null)
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

    /**
     * Frais de port des commandes d'une période (lot B3 bis, 12/11/2026, `docs/gestion-mise-a-niveau.md` § 7) : une ligne par commande,
     * `fraisDePort` null quand elle n'en a pas, et les totaux (les applications n'additionnent rien).
     *
     * @param iterable<Commande> $commandes dans l'ordre de la liste des commandes de la période
     *
     * @return array<string, mixed>
     */
    public function overview(iterable $commandes, string $host, string $siteCurrency): array
    {
        $rows = [];
        $withShipping = 0;
        $amount = 0;
        foreach ($commandes as $commande) {
            $frais = $commande->getFraisDePort();
            $dto = $frais !== null ? new \App\Dto\FraisDePortOutputDTO($frais, $host, $siteCurrency) : null;
            if ($dto !== null) {
                ++$withShipping;
                $amount += $dto->priceConverted;
            }
            $fournisseur = $commande->getFournisseur();
            $rows[] = [
                'commandeId' => $commande->getId(), 'name' => $commande->getName(), 'date' => $commande->getDate()->format('Y-m-d H:i:s'), 'kind' => $commande->getKind(),
                'currency' => $commande->getCurrency() ?? $siteCurrency, 'siteCurrency' => $siteCurrency, 'exchangeRate' => $commande->getCurrency() === null ? null : $commande->getExchangeRate(),
                'fournisseur' => $fournisseur !== null ? ['id' => $fournisseur->getId(), 'name' => $fournisseur->getName()] : null,
                'fraisDePort' => $dto,
            ];
        }

        return ['currency' => $siteCurrency, 'siteCurrency' => $siteCurrency, 'commandes' => $rows, 'totals' => ['commandes' => count($rows), 'withShipping' => $withShipping, 'amount' => $amount]];
    }

    public function createFraisDePort(Commande $commande, FraisDePortInputDTO $inputDTO): FraisDePort
    {
        $em = $this->emProvider->getEntityManager();
        // lot G4 (09/11/2026) : un seul frais de port par commande (409), commande fermée (409), transporteur inconnu (404)
        $this->periodGuard?->assertCommandeWritable($commande); // figée ou datée dans une période fermée (lot P1, 10/11/2026)
        if ($commande->getIsClosed()) {
            throw new \App\Gestion\Services\GestionConflictException('COMMANDE_CLOSED', 'La commande est figée.');
        }
        if ($commande->getFraisDePort()) {
            throw new \App\Gestion\Services\GestionConflictException('SHIPPING_COST_ALREADY_SET', 'La commande a déjà un frais de port : supprimez-le avant d\'en créer un autre.');
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
            throw new \App\Gestion\Services\GestionConflictException('TRANSPORTEUR_NOT_FOUND', 'Transporteur inconnu : ' . $inputDTO->transporteurId, 404);
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
            // La commande en mémoire ne doit plus le porter : sinon l'écriture suivante de la même requête (le journal de la purge
            // ci-dessous, quand un justificatif est à purger) le recréait par cascade, sous un nouvel identifiant (correctif du 12/11/2026)
            $commande->setFraisDePort(null);
            $em->flush();
            $this->documents?->purge(); // justificatif de transport dont le frais de port vient de disparaître (DOC-ORPH, 12/11/2026)
        }
    }
}