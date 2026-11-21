<?php

namespace App\Abo\Services;

use Doctrine\DBAL\Connection;

/**
 * Règles de la liste d'attente sans dépendance (lot ABO-4, 21/11/2026, `docs/boutique.md` § « Liste d'attente, rappels et calendrier ») :
 * appelées par l'agenda, les droits, l'écouteur Doctrine et le passage horaire, dans la transaction de l'appelant.
 *
 * Une attente ouverte (`waiting`, `offered`) se ferme d'elle-même (`left`, décision du chef du 21/11/2026) quand :
 * - le droit qu'elle porte est utilisé ailleurs (une autre attente acceptée, une date posée directement, « Utiliser ») : `right_used` ;
 * - l'abonnement est en pause, résilié, impayé ou se termine avant le créneau : `subscription` ;
 * - le créneau est supprimé ou annulé : `slot_gone` ; ou commencé : `slot_past` ;
 * - la prestation est retirée de la formule : `benefit_removed`.
 */
final class AboWaitlist
{
    public const WAITING = 'waiting';
    public const OFFERED = 'offered';
    public const ACCEPTED = 'accepted';
    public const EXPIRED = 'expired';
    public const LEFT = 'left';
    public const OPEN = [self::WAITING, self::OFFERED];

    /** Attentes ouvertes au plus par abonnement (contrat ABO-4) */
    public const MAX_OPEN = 3;
    /** Délai pour confirmer une place offerte (décision du propriétaire § 7.7) */
    public const OFFER_HOURS = 2;
    /** Plus de promotion automatique à moins d'une heure du début ; l'offre court au plus jusqu'à une heure avant */
    public const NO_AUTO_MINUTES = 60;

    /**
     * Ferme les attentes ouvertes qui n'ont plus lieu d'être, pour un abonnement, un créneau, ou tout le site. Rend le nombre fermé.
     */
    public static function closeStale(Connection $conn, ?int $subscriptionId = null, ?int $slotId = null): int
    {
        $now = self::utc(new \DateTimeImmutable());
        $where = "w.status IN ('waiting', 'offered')" . ($subscriptionId !== null ? ' AND w.subscription_id = ' . $subscriptionId : '') . ($slotId !== null ? ' AND w.slot_id = ' . $slotId : '');

        return $conn->executeStatement("UPDATE benefit_waitlist w SET status = 'left', closed_at = :now, closed_reason = x.reason FROM (
                SELECT w.id, CASE
                    WHEN sl.id IS NULL OR sl.status <> 'open' THEN 'slot_gone'
                    WHEN s.status NOT IN ('active', 'trialing') OR (s.cancel_at IS NOT NULL AND s.cancel_at <= sl.starts_at)
                        OR (s.cancel_at_period_end AND s.current_period_end IS NOT NULL AND s.current_period_end <= sl.starts_at) THEN 'subscription'
                    WHEN NOT EXISTS (SELECT 1 FROM plan_benefit pb WHERE pb.plan_id = s.plan_id AND pb.benefit_id = w.benefit_id) THEN 'benefit_removed'
                    WHEN o.id IS NULL OR o.status <> 'available' THEN 'right_used'
                    WHEN sl.starts_at <= :now THEN 'slot_past'
                END AS reason
                FROM benefit_waitlist w JOIN subscription s ON s.id = w.subscription_id LEFT JOIN benefit_slot sl ON sl.id = w.slot_id
                LEFT JOIN benefit_occurrence o ON o.id = w.occurrence_id
                WHERE $where) x WHERE w.id = x.id AND x.reason IS NOT NULL", ['now' => $now]);
    }

    /** Les places offertes encore valables d'un créneau (elles comptent dans la capacité : personne ne les prend pendant l'offre) */
    public static function activeOffers(Connection $conn, int $slotId, ?\DateTimeImmutable $now = null): int
    {
        return (int) $conn->fetchOne("SELECT COUNT(*) FROM benefit_waitlist WHERE slot_id = ? AND status = 'offered' AND offer_expires_at > ?", [$slotId, self::utc($now ?? new \DateTimeImmutable())]);
    }

    /** @param list<int> $ids @return array<int, int> places offertes valables, par créneau */
    public static function activeOffersBySlot(Connection $conn, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($conn->fetchAllAssociative("SELECT slot_id, COUNT(*) AS n FROM benefit_waitlist WHERE slot_id IN (?) AND status = 'offered' AND offer_expires_at > ? GROUP BY slot_id",
            [$ids, self::utc(new \DateTimeImmutable())], [\Doctrine\DBAL\ArrayParameterType::INTEGER, \PDO::PARAM_STR]) as $r) {
            $out[(int) $r['slot_id']] = (int) $r['n'];
        }

        return $out;
    }

    /** L'échéance d'une offre faite maintenant : 2 h, et au plus tard une heure avant la séance (l'heure du début pour une offre de l'équipe à moins d'1 h) */
    public static function offerDeadline(\DateTimeImmutable $now, \DateTimeImmutable $start): \DateTimeImmutable
    {
        $twoHours = $now->modify('+' . self::OFFER_HOURS . ' hours');
        $hourBefore = $start->modify('-' . self::NO_AUTO_MINUTES . ' minutes');
        $limit = $hourBefore > $now ? $hourBefore : $start;

        return $twoHours < $limit ? $twoHours : $limit;
    }

    public static function utc(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
