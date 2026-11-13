<?php

namespace App\Gestion\Services\Document;

use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Gestion\Services\PeriodCalendar;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Commandes auxquelles une facture fournisseur peut s'ajouter (lot IA-FACT-2-A, 13/11/2026, `docs/conversation-gestion.md` § 7) : même
 * fournisseur, commande ordinaire (pas une commande de reprise d'historique), non figée, datée dans une période ouverte, dans la devise
 * du fournisseur. Une seule règle pour la question « target » du fil, l'aperçu et la confirmation.
 */
class InvoiceTargetPolicy
{
    public const REASONS = ['SUPPLIER_MISMATCH', 'REPRISE', 'COMMANDE_CLOSED', 'PERIOD_CLOSED', 'CURRENCY_MISMATCH'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly PeriodCalendar $calendar, private readonly TenantCurrencyProvider $currency)
    {
    }

    /** Raison pour laquelle la commande n'est pas admise pour ce fournisseur, null si elle l'est (lecture seule : aucune période créée) */
    public function reason(Commande $commande, ?Fournisseur $supplier): ?string
    {
        if ($supplier === null || $supplier->getId() === null || $commande->getFournisseur()?->getId() !== $supplier->getId()) {
            return 'SUPPLIER_MISMATCH';
        }
        if ($commande->isReprise()) {
            return 'REPRISE';
        }
        if ($commande->getIsClosed()) {
            return 'COMMANDE_CLOSED';
        }
        $period = $commande->getCollections() ?? ($commande->getDate() !== null ? $this->calendar->findByDate($commande->getDate()) : null);
        if ($period !== null && $this->calendar->isClosed($period)) {
            return 'PERIOD_CLOSED';
        }
        $site = strtoupper($this->currency->code());
        if (strtoupper($commande->getCurrency() ?? $site) !== strtoupper($supplier->getCurrency() ?? $site)) {
            return 'CURRENCY_MISMATCH';
        }

        return null;
    }

    /** @return list<Commande> commandes admises du fournisseur, de la plus récente à la plus ancienne */
    public function eligible(Fournisseur $supplier): array
    {
        $commandes = $this->emProvider->getEntityManager()->getRepository(Commande::class)->findBy(['fournisseur' => $supplier, 'kind' => Commande::KIND_STANDARD], ['date' => 'DESC', 'id' => 'DESC'], 50);

        return array_values(array_filter($commandes, fn (Commande $c) => $this->reason($c, $supplier) === null));
    }
}
