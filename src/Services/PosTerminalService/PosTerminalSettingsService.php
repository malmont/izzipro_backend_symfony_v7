<?php

namespace App\Services\PosTerminalService;

use App\Entity\Entreprise;
use App\Services\StripeService\StripeService;
use App\Services\TenantEntityManagerProvider;

/**
 * Réglages du terminal de paiement par site (C4, 23/10/2026 ; fiche entreprise, panneau EasyAdmin « Caisse (POS) »).
 * Valeurs par défaut : commissions 1 % (100 centièmes de %), Interac selon le pays (CA), attente de la carte 120 s,
 * annulation d'une autorisation non capturée 30 min, paiement sans vente après 5 min, annulation par le client oui,
 * panier sur le lecteur non.
 */
class PosTerminalSettingsService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly StripeService $stripe)
    {
    }

    public function settings(): array
    {
        $e = $this->entreprise();

        return [
            'feeCardBp' => $e?->getPosTerminalFeeCardBp() ?? 100,
            'feeInteracBp' => $e?->getPosTerminalFeeInteracBp() ?? 100,
            'interac' => $this->interacAccepted($e),
            'cardWaitSeconds' => $e?->getPosTerminalCardWaitSeconds() ?? 120,
            'authCancelMinutes' => $e?->getPosTerminalAuthCancelMinutes() ?? 30,
            'orphanMinutes' => $e?->getPosTerminalOrphanMinutes() ?? 5,
            'customerCancel' => $e?->isPosTerminalCustomerCancel() ?? true,
            'cartDisplay' => $e?->isPosTerminalCartDisplay() ?? false,
            'currency' => strtolower($e?->getCurrency() ?? 'cad'),
        ];
    }

    /** Moyens acceptés sur le terminal */
    public function paymentMethodTypes(): array
    {
        return $this->interacAccepted($this->entreprise()) ? ['card_present', 'interac_present'] : ['card_present'];
    }

    /** Commission plateforme (cents) pour un montant et un type de carte ; nulle pour un site interne */
    public function fee(int $amount, string $paymentMethodType): int
    {
        if ($this->stripe->accountOptions() === []) {
            return 0;
        }
        $bp = $paymentMethodType === 'interac_present' ? ($this->entreprise()?->getPosTerminalFeeInteracBp() ?? 100) : ($this->entreprise()?->getPosTerminalFeeCardBp() ?? 100);

        return (int) round($amount * $bp / 10000);
    }

    private function interacAccepted(?Entreprise $e): bool
    {
        return $e?->getPosTerminalInterac() ?? ($this->stripe->resolveTenantCountryCode() === 'CA');
    }

    private function entreprise(): ?Entreprise
    {
        return $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
    }
}
