<?php

namespace App\Services\ReservationService;

use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Lot CMD-WEB-1 (20/11/2026, contrat validé par le chef) : les rendez-vous de la BOUTIQUE (« Réservations de Prestations » d'EasyAdmin),
 * lus et confirmés ou annulés depuis la gestion web et l'assistant du site. La table `reservation` porte aussi les entretiens de
 * Mémoires Vivantes (livre ou biographe) : ils ne sont JAMAIS servis ici (`ReservationRepository::SHOP_WHERE`, 404 sans dire pourquoi).
 *
 * Le changement d'état est le SEUL code du geste : les boutons Confirmer et Annuler d'EasyAdmin passent par `changeStatus` (toutes les lignes,
 * comme avant). Ligne verrouillée (FOR UPDATE), relue, puis le courriel au client après l'écriture. Un rendez-vous de la boutique ne porte
 * aujourd'hui aucun paiement (aucune colonne, aucune table qui le cite) : l'annulation ne rembourse rien et n'a rien à rembourser ; l'essai
 * `ShopBookingApiTest::testABookingCarriesNoPayment` casse si un lien de paiement apparaît (il faudra alors la garde 409 RESERVATION_PAID).
 */
class ShopBookingService
{
    public const STATUSES = ['pending', 'confirmed', 'cancelled'];
    public const CONFIRMED = 'confirmed';
    public const CANCELLED = 'cancelled';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ReservationMailerService $mailer,
        private readonly ContentAuditRecorder $audit,
        private readonly LoggerInterface $logger,
        private readonly \App\Services\CaisseService\StoreClock $clock,
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function list(?string $status, ?string $from, ?string $to, int $offset, int $limit): array
    {
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new ShopBookingException(422, 'STATUS_INVALID', 'État attendu : pending, confirmed ou cancelled.', 'status');
        }
        $page = $this->repository()->shopPage($status, self::day($from, 'from'), self::day($to, 'to'), $this->clock->day(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))), $offset, $limit);

        return ['items' => array_map(self::present(...), $page['items']), 'total' => $page['total']];
    }

    /** @return array<string, mixed> */
    public function one(int $id): array
    {
        return self::present($this->repository()->findShop($id) ?? throw self::notFound());
    }

    /**
     * Confirmer ou annuler. Rejouer le même geste ne change rien et n'envoie aucun second courriel (`changed: false`) ; confirmer un
     * rendez-vous annulé : 409 RESERVATION_CANCELLED. Pour l'écran EasyAdmin, comme avant : `$shopOnly` false (toutes les lignes) et
     * `$reopen` true (son bouton Confirmer reconfirme un rendez-vous annulé).
     *
     * @return array{reservation: array<string, mixed>, changed: bool, emailRequested: bool}
     */
    public function changeStatus(int $id, string $status, bool $shopOnly, string $by, string $locale = 'fr', ?string $host = null, bool $reopen = false): array
    {
        if (!in_array($status, [self::CONFIRMED, self::CANCELLED], true)) {
            throw new ShopBookingException(422, 'STATUS_INVALID', 'Geste attendu : confirmer ou annuler.', 'status');
        }
        // transaction explicite : un refus (404, 409) ne ferme pas le gestionnaire d'entités (wrapInTransaction le ferme sur toute exception)
        $em = $this->emProvider->getEntityManager();
        $connection = $em->getConnection();
        $connection->beginTransaction();
        try {
            $reservation = $this->repository()->lockForStatus($id, $shopOnly);
            if ($reservation === null) {
                if ($shopOnly && $this->repository()->find($id) !== null) {
                    $this->logger->warning('[reservations] geste de la boutique refusé sur un entretien de Mémoires Vivantes', ['reservationId' => $id]);
                }
                throw self::notFound();
            }
            $em->refresh($reservation); // relue sous le verrou (l'objet a pu être chargé avant)
            $before = $reservation->getStatus();
            if ($status === self::CONFIRMED && $before === self::CANCELLED && !$reopen) {
                throw new ShopBookingException(409, 'RESERVATION_CANCELLED', 'Ce rendez-vous est annulé : il ne peut plus être confirmé.');
            }
            if ($before !== $status) {
                $reservation->setStatus($status);
                $em->flush();
                // le journal dans la même transaction : un journal qui échoue défait le changement (jamais un état changé sans trace)
                $this->audit->record('reservation', $id, $status === self::CONFIRMED ? 'confirm' : 'cancel', ['status' => $before], ['status' => $status, 'by' => $by]);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->isTransactionActive() && $connection->rollBack();
            throw $e;
        }
        $changed = $before !== $status;
        $emailRequested = false;
        if ($changed && (string) $reservation->getClientEmail() !== '') {
            try {
                // le service d'envoi écrit lui-même ses échecs au journal sans les remonter : « demandé », jamais « reçu »
                $this->mailer->sendStatusChangeEmail($reservation, $status, $locale, $host);
                $emailRequested = true;
            } catch (\Throwable $e) {
                $this->logger->warning('[reservations] courriel de changement d\'état non envoyé', ['reservationId' => $id, 'error' => $e::class]);
            }
        }

        return ['reservation' => self::present($reservation), 'changed' => $changed, 'emailRequested' => $emailRequested];
    }

    /** @return array<string, mixed> */
    public static function present(Reservation $r): array
    {
        return ['id' => (int) $r->getId(), 'serviceId' => $r->getServiceId(), 'serviceName' => $r->getServiceName(), 'forfaitName' => $r->getForfaitName(),
            'date' => $r->getReservationDate()?->format('Y-m-d'), 'slot' => $r->getReservationSlot(), 'guests' => $r->getNumberOfGuests(),
            'clientName' => $r->getClientName(), 'clientEmail' => $r->getClientEmail(), 'clientPhone' => $r->getClientPhone(), 'notes' => $r->getNotes(),
            'status' => $r->getStatus(), 'createdAt' => $r->getCreatedAt()?->format('Y-m-d H:i')];
    }

    /** Le directeur entré pilote, il ne sert pas le client : ni courriel, ni téléphone, ni notes du client (minimisation, comme la fiche commande) */
    public static function forAudience(array $booking, string $audience): array
    {
        return $audience === \App\Services\OrderService\OrderDeskView::DIRECTOR ? array_diff_key($booking, array_flip(['clientEmail', 'clientPhone', 'notes'])) : $booking;
    }

    private static function day(?string $value, string $field): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new ShopBookingException(422, 'DATE_INVALID', 'Date attendue au format AAAA-MM-JJ.', $field);
        }

        return $day;
    }

    private static function notFound(): ShopBookingException
    {
        return new ShopBookingException(404, 'RESERVATION_NOT_FOUND', 'Rendez-vous introuvable.');
    }

    private function repository(): ReservationRepository
    {
        /** @var ReservationRepository $repository */
        $repository = $this->emProvider->getEntityManager()->getRepository(Reservation::class);

        return $repository;
    }
}
