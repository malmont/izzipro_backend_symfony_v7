<?php

namespace App\Services\Booking;

use App\Entity\Product;
use App\Services\TenantEntityManagerProvider;

/**
 * Promotions de location (lot LOC3, 11/11/2026, `docs/locations-gestion.md` § 3), en lecture pour le calcul commun des devis (boutique et caisse)
 * et pour la fiche de l'article. Règle : une location reçoit la promotion de son article si son **jour de début**, lu au fuseau de la boutique
 * (`BookingClock`), tombe dans [starts_at, ends_at[ (bornes UTC des jours du magasin) et sur un jour coché. Aucune promotion : rien ne change.
 */
class RentalPromotionService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly BookingClock $clock)
    {
    }

    /** @return array{id: int, percent: int, weekdays: list<int>}|null promotion de l'article pour une location qui commence à `$start` */
    public function activeFor(int $productId, \DateTimeInterface $start): ?array
    {
        $tz = $this->clock->timezone();
        $day = \DateTimeImmutable::createFromInterface($start)->setTimezone($tz);
        $dayStart = BookingClock::utc(new \DateTimeImmutable($day->format('Y-m-d') . ' 00:00:00', $tz))->format('Y-m-d H:i:s');
        $weekday = (int) $day->format('N');
        foreach ($this->conn()->fetchAllAssociative('SELECT id, percent, weekdays FROM rental_promotion WHERE product_id = ? AND starts_at <= ? AND ends_at > ? ORDER BY id', [$productId, $dayStart, $dayStart]) as $r) {
            $days = array_map('intval', json_decode((string) $r['weekdays'], true) ?: []);
            if (in_array($weekday, $days, true)) {
                return ['id' => (int) $r['id'], 'percent' => (int) $r['percent'], 'weekdays' => $days];
            }
        }

        return null;
    }

    /**
     * Fiche de l'article (`rental.promotion` de ProductCommerceDto) : la promotion en cours, sinon la prochaine ; null s'il n'y en a pas.
     * `startDate` / `endDate` : jours du magasin, inclus ; `startsAt` / `endsAt` : mêmes bornes en instants UTC (fin exclusive).
     *
     * @return array{percent: int, startDate: string, endDate: string, startsAt: string, endsAt: string, weekdays: list<int>}|null
     */
    public static function display(Product $product): ?array
    {
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);
        foreach ($product->getRentalPromotions() as $p) {
            $from = new \DateTimeImmutable($p->getStartsAt()->format('Y-m-d H:i:s'), $utc);
            $to = new \DateTimeImmutable($p->getEndsAt()->format('Y-m-d H:i:s'), $utc);
            if ($to <= $now || $to <= $from) {
                continue;
            }

            return ['percent' => $p->getPercent(), 'startDate' => $p->getStartDate()->format('Y-m-d'), 'endDate' => $p->getEndDate()->format('Y-m-d'),
                'startsAt' => $from->format(\DateTimeInterface::ATOM), 'endsAt' => $to->format(\DateTimeInterface::ATOM), 'weekdays' => $p->getWeekdays()];
        }

        return null;
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
