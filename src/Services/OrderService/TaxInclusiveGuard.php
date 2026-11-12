<?php

namespace App\Services\OrderService;

use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\BoutiqueSettingsService\SiteCurrencyService;

/**
 * Activation des prix taxes comprises (F18 partie 2b) : refusée si la boutique est sur Stripe Tax (l'extraction n'a de sens qu'avec la
 * table), si un taux du magasin n'est pas un multiple exact de 0,01 % (9,975 % : l'extraction en dix-millièmes serait fausse) ou si le
 * site a déjà des montants enregistrés (même verrou que la devise : le réglage change le sens de tous les prix du catalogue).
 */
class TaxInclusiveGuard
{
    public function __construct(private readonly TaxEngine $taxes, private readonly OrderTaxAddressResolver $storeAddress, private readonly BoutiqueSettingsService $settings, private readonly SiteCurrencyService $lock)
    {
    }

    /** @throws \DomainException message à afficher tel quel */
    public function assertCanEnable(): void
    {
        if ($this->taxes->provider() === TaxEngine::PROVIDER_STRIPE) {
            throw new \DomainException('Prix taxes comprises impossibles avec Stripe Tax (commerce.taxProvider = stripe) : passez la boutique sur la table des taxes d\'abord.');
        }
        $address = TaxEngine::normalizeAddress($this->storeAddress->forStore());
        foreach ($address ? $this->taxes->applicableTaxes($address) : [] as $tax) {
            if (!TaxInclusive::isExactRate((float) $tax->getRate())) {
                throw new \DomainException(sprintf('Prix taxes comprises impossibles : le taux de « %s » (%s %%) n\'est pas exact au millionième.', $tax->getName(), rtrim(rtrim(number_format((float) $tax->getRate() * 100, 4, ',', ''), '0'), ',')));
            }
        }
        if (($message = $this->lock->lockMessage()) !== null) {
            throw new \DomainException(str_replace('Devise verrouillée', 'Prix taxes comprises verrouillés', $message));
        }
    }

    /** Changement du réglage (activation ou désactivation) : verrouillé dès le premier montant enregistré */
    public function assertCanChange(bool $enable): void
    {
        if ($enable) {
            $this->assertCanEnable();

            return;
        }
        if (($message = $this->lock->lockMessage()) !== null) {
            throw new \DomainException(str_replace('Devise verrouillée', 'Prix taxes comprises verrouillés', $message));
        }
    }
}
