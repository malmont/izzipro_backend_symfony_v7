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

    public function __construct(TenantEntityManagerProvider $emProvider, private readonly ?\App\Gestion\Services\PeriodGuard $periodGuard = null, private readonly ?\App\Gestion\Services\Document\GestionDocumentService $documents = null,
        private readonly ?\App\Services\ContentAuditService\ContentAuditRecorder $audit = null)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * Modification partielle du frais de port d'une commande (lot EDIT, 12/11/2026, `docs/gestion-mise-a-niveau.md` § 7) : seules les
     * clés envoyées changent. Mêmes gardes que la création et la suppression : commande figée ou période fermée → 409. Le prix reste
     * modifiable quand le frais de port vient d'un document lu par IA (correction du gérant) : le journal des contenus garde l'avant,
     * l'après et l'identifiant du document, pour retrouver l'écart.
     *
     * @param array<string, mixed> $data
     */
    public function update(Commande $commande, array $data): FraisDePort
    {
        $this->periodGuard?->assertCommandeWritable($commande); // 409 PERIOD_CLOSED / COMMANDE_CLOSED
        if ($commande->getIsClosed()) {
            throw new \App\Gestion\Services\GestionConflictException('COMMANDE_CLOSED', 'La commande est figée.');
        }
        $fraisDePort = $commande->getFraisDePort();
        if ($fraisDePort === null) {
            throw new \App\Gestion\Services\GestionConflictException('FRAIS_DE_PORT_NOT_FOUND', 'Cette commande n\'a pas de frais de port.', 404);
        }
        $em = $this->emProvider->getEntityManager();
        $input = new \App\Gestion\Services\GestionInput($data);
        $before = self::snapshot($fraisDePort);
        $name = array_key_exists('name', $data) ? $input->text('name') : null;
        $price = array_key_exists('price', $data) ? $input->cents('price') : null;
        $transporteurId = array_key_exists('transporteur', $data) ? $input->id('transporteur') : null;
        $input->check();
        $transporteur = null;
        if ($transporteurId !== null && ($transporteur = $em->getRepository(Transporteur::class)->find($transporteurId)) === null) {
            throw new \App\Gestion\Services\GestionConflictException('TRANSPORTEUR_NOT_FOUND', 'Transporteur inconnu : ' . $transporteurId, 404);
        }
        if ($name !== null) {
            $fraisDePort->setName($name);
        }
        if ($price !== null) {
            $fraisDePort->setPrice($price);
        }
        if ($transporteur !== null) {
            $fraisDePort->setTransporteur($transporteur);
        }
        if (array_key_exists('facture', $data)) {
            $fraisDePort->setFacture($input->text('facture', false)); // vide : null (lot EDIT-JOURNAL)
        }
        if (array_key_exists('tracknumber', $data)) {
            $fraisDePort->setTracknumber($input->text('tracknumber', false));
        }
        $em->flush();
        $after = self::snapshot($fraisDePort);
        if ($after !== $before) {
            $document = $fraisDePort->getDocument()?->getId();
            $this->audit?->record('frais-de-port', $fraisDePort->getId(), 'update', $before + ['commandeId' => $commande->getId(), 'documentId' => $document], $after + ['commandeId' => $commande->getId(), 'documentId' => $document], array_keys(array_diff_assoc($after, $before)));
        }

        return $fraisDePort;
    }

    /** @return array<string, mixed> */
    private static function snapshot(FraisDePort $f): array
    {
        return ['name' => $f->getName(), 'facture' => $f->getFacture(), 'tracknumber' => $f->getTracknumber(), 'price' => $f->getPrice(), 'transporteurId' => $f->getTransporteur()?->getId()];
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
        // champ facultatif vide : null, comme un champ jamais rempli (lot EDIT-JOURNAL, 12/11/2026)
        $fraisDePort->setFacture(\App\Gestion\Services\GestionInput::blank($inputDTO->facture));
        $fraisDePort->setTracknumber(\App\Gestion\Services\GestionInput::blank($inputDTO->tracknumber));
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
            $id = $fraisDePort->getId();
            $before = self::snapshot($fraisDePort) + ['commandeId' => $commande->getId(), 'documentId' => $fraisDePort->getDocument()?->getId()];
            $em->remove($fraisDePort);
            // La commande en mémoire ne doit plus le porter : sinon l'écriture suivante de la même requête (le journal de la purge
            // ci-dessous, quand un justificatif est à purger) le recréait par cascade, sous un nouvel identifiant (correctif du 12/11/2026)
            $commande->setFraisDePort(null);
            $em->flush();
            $this->audit?->record('frais-de-port', $id, 'delete', $before, null); // lot EDIT-JOURNAL (12/11/2026)
            $this->documents?->purge(); // justificatif de transport dont le frais de port vient de disparaître (DOC-ORPH, 12/11/2026)
        }
    }
}