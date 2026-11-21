<?php

namespace App\Abo\Services;

use App\Entity\Subscription;
use App\Services\CaisseService\StoreClock;
use App\Services\Calendar\IcsBuilder;
use App\Services\SubscriptionService\SubscriptionMailer;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Le rappel de la veille (lot ABO-4, 21/11/2026, décision 10 du propriétaire, `docs/boutique.md` § « Liste d'attente, rappels et
 * calendrier ») : à partir de 18 h du magasin, par le passage horaire `app:abo:periodes`, un courriel par date planifiée du lendemain, avec
 * la pièce `.ics`. INDISPENSABLE (décision du chef) : seule la préférence de l'abonné le coupe (`subscription.reminder_emails`). Une date
 * n'est rappelée qu'une fois (`benefit_occurrence.reminded_at`, pris avant l'envoi) ; une date déplacée est rappelée de nouveau.
 */
class AboReminderService
{
    public const FROM_HOUR = 18;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenant,
        private readonly StoreClock $clock,
        private readonly SubscriptionMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Les rappels dus maintenant (rien avant 18 h du magasin) @return int envoyés */
    public function sendDue(?\DateTimeImmutable $now = null): int
    {
        $conn = $this->conn();
        if ($conn->fetchOne("SELECT 1 FROM information_schema.columns WHERE table_name = 'benefit_occurrence' AND column_name = 'reminded_at' AND table_schema = current_schema()") === false) {
            return 0;
        }
        $now ??= new \DateTimeImmutable();
        $tz = $this->clock->timezone();
        $local = $now->setTimezone($tz);
        if ((int) $local->format('G') < self::FROM_HOUR) {
            return 0;
        }
        $from = $local->modify('tomorrow');
        $to = $from->modify('+1 day');
        $rows = $conn->fetchAllAssociative("SELECT o.id, o.subscription_id FROM benefit_occurrence o JOIN subscription s ON s.id = o.subscription_id
            WHERE o.status = 'scheduled' AND o.reminded_at IS NULL AND o.scheduled_at >= ? AND o.scheduled_at < ? AND s.reminder_emails AND s.status IN ('active', 'trialing', 'past_due')
            ORDER BY o.id", [self::utc($from), self::utc($to)]);
        $sent = 0;
        foreach ($rows as $r) {
            // pris avant l'envoi : deux passages simultanés n'envoient jamais deux rappels pour la même date
            if ($conn->executeStatement('UPDATE benefit_occurrence SET reminded_at = ? WHERE id = ? AND reminded_at IS NULL', [self::utc($now), (int) $r['id']]) !== 1) {
                continue;
            }
            try {
                $sent += $this->send($conn, (int) $r['id'], (int) $r['subscription_id']) ? 1 : 0;
            } catch (\Throwable $e) {
                $this->logger->error('[ABO-4] Rappel non envoyé (' . $e::class . ').', ['occurrence' => (int) $r['id']]);
            }
        }

        return $sent;
    }

    /** L'identifiant stable d'une date dans les agendas (le flux, le rappel et l'annulation parlent du même événement) */
    public function uid(int $occurrenceId): string
    {
        return sprintf('abo-%s-%d@izzipro', (string) $this->tenant->getTenantCode(), $occurrenceId);
    }

    /**
     * La pièce `.ics` d'une ou plusieurs dates (rappel : REQUEST ; séance annulée par la boutique : CANCEL).
     *
     * @param list<int> $occurrenceIds
     */
    public function ics(array $occurrenceIds, string $language, bool $cancelled = false): ?string
    {
        if ($occurrenceIds === []) {
            return null;
        }
        $events = [];
        $conn = $this->conn();
        foreach ($conn->fetchAllAssociative('SELECT o.id, o.benefit_id, COALESCE(o.scheduled_at, sl.starts_at) AS starts_at, COALESCE(o.scheduled_end, sl.ends_at) AS ends_at, COALESCE(sl.location, b.location) AS location
            FROM benefit_occurrence o JOIN benefit b ON b.id = o.benefit_id LEFT JOIN benefit_slot sl ON sl.id = o.slot_id WHERE o.id IN (?) ORDER BY o.id',
            [$occurrenceIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]) as $r) {
            if ($r['starts_at'] === null) {
                continue;
            }
            $benefit = $this->emProvider->getEntityManager()->getRepository(\App\Abo\Entity\Benefit::class)->find((int) $r['benefit_id']);
            $start = new \DateTimeImmutable((string) $r['starts_at'], new \DateTimeZone('UTC'));
            $events[] = ['uid' => $this->uid((int) $r['id']), 'start' => $start, 'end' => $r['ends_at'] !== null ? new \DateTimeImmutable((string) $r['ends_at'], new \DateTimeZone('UTC')) : $start->modify('+1 hour'),
                'summary' => (string) ($benefit?->getName($language) ?? ''), 'location' => $r['location'] !== null ? (string) $r['location'] : null, 'cancelled' => $cancelled, 'sequence' => $cancelled ? 1 : 0];
        }

        return $events !== [] ? IcsBuilder::calendar($events, (string) ($events[0]['summary'] ?? ''), $cancelled ? IcsBuilder::METHOD_CANCEL : IcsBuilder::METHOD_REQUEST) : null;
    }

    private function send(Connection $conn, int $occurrenceId, int $subscriptionId): bool
    {
        $subscription = $this->emProvider->getEntityManager()->getRepository(Subscription::class)->find($subscriptionId);
        if ($subscription === null) {
            return false;
        }
        $language = $this->mailer->languageOf($subscription);
        $r = $conn->fetchAssociative('SELECT o.benefit_id, o.scheduled_at, COALESCE(sl.location, b.location) AS location FROM benefit_occurrence o JOIN benefit b ON b.id = o.benefit_id
            LEFT JOIN benefit_slot sl ON sl.id = o.slot_id WHERE o.id = ?', [$occurrenceId]);
        if ($r === false) {
            return false;
        }
        $benefit = $this->emProvider->getEntityManager()->getRepository(\App\Abo\Entity\Benefit::class)->find((int) $r['benefit_id']);
        $rows = ['subscription_notice.row.session' => (string) $benefit?->getName($language),
            'subscription_notice.row.date' => (new \DateTimeImmutable((string) $r['scheduled_at'], new \DateTimeZone('UTC')))->setTimezone($this->clock->timezone())->format('d/m/Y H:i')];
        if ($r['location'] !== null && $r['location'] !== '') {
            $rows['subscription_notice.row.place'] = (string) $r['location'];
        }

        return $this->mailer->notify($subscription, 'session_reminder', '', ['rows' => $rows, 'ics' => $this->ics([$occurrenceId], $language)]);
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }

    private static function utc(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
