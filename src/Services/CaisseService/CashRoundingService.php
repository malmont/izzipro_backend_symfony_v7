<?php

namespace App\Services\CaisseService;

use App\Entity\Entreprise;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Arrondi des paiements en espèces (19/10/2026) : au Canada, la part payée en espèces s'arrondit aux 5 ¢ les plus
 * proches (,01 et ,02 vers le bas ; ,03 à ,07 vers ,05 ; ,08 et ,09 vers le haut). Pas réglable par site
 * (Entreprise::posCashRoundingStep) ; à défaut 5 ¢ pour le CAD, aucun arrondi pour les autres devises.
 */
class CashRoundingService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly TenantCurrencyProvider $currency)
    {
    }

    /** Pas d'arrondi en cents (1 = aucun arrondi) */
    public function step(): int
    {
        $configured = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([])?->getPosCashRoundingStep();
        if ($configured !== null && $configured > 0) {
            return $configured;
        }

        return strtoupper($this->currency->code()) === 'CAD' ? 5 : 1;
    }

    /**
     * Contrôle des paiements d'une vente en caisse (create-multi-payment) contre le total du serveur. La part espèces
     * est envoyée déjà arrondie : (non espèces + espèces) = total + arrondi, l'arrondi valant espèces − (total − non
     * espèces), au plus un demi-pas (2 ¢ en CAD). À défaut, tolérance historique d'1 cent sans arrondi.
     *
     * @return int arrondi des espèces (cents, signé : effet sur le tiroir)
     * @throws CashRegisterException 400 PAYMENT_TOTAL_MISMATCH
     */
    public function checkCounterPayment(int $total, int $cash, int $nonCash): int
    {
        $step = $this->step();
        if ($cash !== 0 && $step > 1) {
            $due = $total - $nonCash;
            if ($cash === $this->round($due, $step) && abs($cash - $due) <= intdiv($step, 2)) {
                return $cash - $due;
            }
        }
        if (abs($cash + $nonCash - $total) <= 1) {
            return 0;
        }

        throw new CashRegisterException('PAYMENT_TOTAL_MISMATCH', sprintf('Le montant payé (%.2f) ne correspond pas au total de la commande (%.2f).', ($cash + $nonCash) / 100, $total / 100), 400, [],
            ['expectedTotal' => $total, 'cashTotal' => $this->round($total, $step)]);
    }

    /** Montant (cents, signé) arrondi au pas le plus proche */
    public function round(int $cents, ?int $step = null): int
    {
        $step ??= $this->step();
        if ($step <= 1) {
            return $cents;
        }

        return (int) (round($cents / $step) * $step);
    }
}
