<?php

namespace App\Services\BoutiqueSettingsService;

use App\Entity\Entreprise;
use App\Services\CaisseService\DenominationDefaults;
use App\Services\CaisseService\DenominationService;
use App\Services\PosAuditService\PosAuditService;
use App\Services\TenantEntityManagerProvider;

/**
 * Devise du site (F18 partie 1, 01/11/2026) : une seule par site, choisie dans la fiche Entreprise (CAD, EUR, USD) tant que le
 * site n'a ni commande (web ou caisse), ni carte-cadeau ou avoir, ni session de caisse ; ensuite verrouillée (les montants
 * enregistrés sont dans l'ancienne devise). Au changement, les coupures par défaut de la nouvelle devise sont créées si le site
 * n'en a aucune dans cette devise (insertions seulement, journal DENOMINATIONS_CREATED).
 */
class SiteCurrencyService
{
    public const SUPPORTED = ['CAD', 'EUR', 'USD'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly DenominationService $denominations, private readonly ?PosAuditService $audit = null)
    {
    }

    public static function isSupported(?string $currency): bool
    {
        return is_string($currency) && in_array(strtoupper($currency), self::SUPPORTED, true);
    }

    /** @return array{orders: int, giftCards: int, sessions: int} ce qui verrouille la devise (tout à 0 : modifiable) */
    public function usage(): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();

        return [
            'orders' => (int) $conn->fetchOne('SELECT COUNT(*) FROM "order"'),
            'giftCards' => (int) $conn->fetchOne('SELECT COUNT(*) FROM pos_gift_card'),
            'sessions' => (int) $conn->fetchOne('SELECT COUNT(*) FROM caisse'),
        ];
    }

    public function isLocked(): bool
    {
        return array_sum($this->usage()) > 0;
    }

    /** Message affiché dans la fiche Entreprise quand la devise ne peut plus changer ; null si elle peut */
    public function lockMessage(): ?string
    {
        $usage = $this->usage();
        if (array_sum($usage) === 0) {
            return null;
        }
        $parts = array_filter([
            $usage['orders'] > 0 ? sprintf('%d commande%s', $usage['orders'], $usage['orders'] > 1 ? 's' : '') : null,
            $usage['giftCards'] > 0 ? sprintf('%d carte%s-cadeau%s ou avoir%s', $usage['giftCards'], $usage['giftCards'] > 1 ? 's' : '', $usage['giftCards'] > 1 ? 'x' : '', $usage['giftCards'] > 1 ? 's' : '') : null,
            $usage['sessions'] > 0 ? sprintf('%d session%s de caisse', $usage['sessions'], $usage['sessions'] > 1 ? 's' : '') : null,
        ]);

        return sprintf('Devise verrouillée : ce site a déjà %s, dont les montants sont dans cette devise. Un changement de devise se fait avec l\'assistance de la plateforme.', implode(', ', $parts));
    }

    /**
     * Applique un changement de devise déjà écrit sur l'entité (avant le flush) : devise connue, site sans montant enregistré.
     *
     * @throws \DomainException devise inconnue ou verrouillée (message à afficher tel quel)
     */
    public function assertChangeAllowed(string $from, string $to): void
    {
        if (!self::isSupported($to)) {
            throw new \DomainException(sprintf('Devise inconnue : %s (devises possibles : %s).', $to, implode(', ', self::SUPPORTED)));
        }
        if (strtoupper($from) !== strtoupper($to) && ($message = $this->lockMessage()) !== null) {
            throw new \DomainException($message);
        }
    }

    /**
     * Après l'enregistrement d'une nouvelle devise : coupures par défaut si le site n'en a aucune dans cette devise ; journal CURRENCY_CHANGED.
     *
     * @return array{created: int, currency: string} nombre de coupures créées (0 : le site en avait déjà, ou devise sans liste)
     */
    public function afterChange(Entreprise $entreprise, string $from, string $source): array
    {
        $to = strtoupper($entreprise->getCurrency());
        $this->audit?->record(PosAuditService::CURRENCY_CHANGED, ['source' => $source, 'from' => strtoupper($from), 'to' => $to], null, null, 'entreprise', $entreprise->getId());
        $created = DenominationDefaults::has($to) ? $this->denominations->createDefaults($to, $source) : 0;

        return ['created' => $created, 'currency' => $to];
    }
}
