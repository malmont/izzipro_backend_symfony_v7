<?php

namespace App\Services\ReservationService;

use App\Dto\ReservationInputDto;
use App\Entity\Entreprise;
use App\Entity\Reservation;
use App\Entity\ServiceOffer;
use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Uid\Uuid;

class ReservationService
{
    public function __construct(
        private TenantEntityManagerProvider $emProvider
    ) {
    }

    public function createReservation(ReservationInputDto $dto, string $host, ?User $currentUser = null): Reservation
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);

        $cleanTenantId = $dto->tenant_id ?: explode('.', $host)[0];
        $resDate = new \DateTime($dto->reservation_date);

        // Résolution du biographe :
        // 1. Paramètre explicite biographer_id
        // 2. Déduction automatique depuis le livre (book_id)
        // 3. Fallback sur l'utilisateur connecté s'il est biographe
        $biographer = null;
        if ($dto->biographer_id) {
            $biographer = $tenantEm->getRepository(User::class)->find($dto->biographer_id);
        } elseif ($dto->book_id) {
            try {
                $book = $tenantEm->getRepository(Book::class)->find(Uuid::fromString($dto->book_id));
                if ($book && $book->getUser()) {
                    $biographer = $book->getUser();
                }
            } catch (\Throwable $e) {
                // Si l'UUID n'est pas valide ou livre introuvable, on continue sans biographe lié
            }
        } elseif ($currentUser) {
            $biographer = $currentUser;
        }

        $connection = $tenantEm->getConnection();
        $connection->beginTransaction();

        try {
            // Verrou transactionnel ciblé :
            // Si un biographe est défini, on verrouille pour ce biographe précis.
            // Cela permet à deux biographes distincts de réserver le même créneau en parallèle !
            if ($dto->reservation_slot) {
                $lockKey = $biographer
                    ? 'reservation:' . $biographer->getId() . ':' . $resDate->format('Y-m-d') . ':' . $dto->reservation_slot
                    : 'reservation:' . $resDate->format('Y-m-d') . ':' . $dto->reservation_slot;

                $connection->executeStatement(
                    'SELECT pg_advisory_xact_lock(hashtext(:key))',
                    ['key' => $lockKey]
                );

                // Vérification anti-doublon ciblée sur le biographe
                if ($this->isSlotBooked($resDate, $dto->reservation_slot, $biographer?->getId())) {
                    throw new \DomainException(sprintf(
                        'Le créneau "%s" du %s est déjà réservé. Veuillez choisir un autre horaire.',
                        $dto->reservation_slot,
                        $resDate->format('d/m/Y')
                    ));
                }
            }

            $reservation = $this->buildReservation($dto, $resDate, $cleanTenantId, $entreprise, $biographer);

            $tenantEm->persist($reservation);
            $tenantEm->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }

        return $reservation;
    }

    private function buildReservation(
        ReservationInputDto $dto,
        \DateTime $resDate,
        string $cleanTenantId,
        ?Entreprise $entreprise,
        ?User $biographer = null
    ): Reservation
    {
        $reservation = new Reservation();
        $reservation->setServiceId($dto->service_id);
        $reservation->setServiceName($dto->service_name);
        $reservation->setReservationDate($resDate);
        $reservation->setReservationSlot($dto->reservation_slot);
        $reservation->setClientName($dto->client_name);
        $reservation->setClientEmail($dto->client_email);
        $reservation->setClientPhone($dto->client_phone);
        $reservation->setNumberOfGuests(max(1, $dto->number_of_guests));
        $reservation->setNotes($dto->notes);
        $reservation->setTenantId($cleanTenantId);
        $reservation->setEntreprise($entreprise);
        $reservation->setStatus('pending');
        $reservation->setCreatedAt(new \DateTime());
        $reservation->setBookId($dto->book_id);
        $reservation->setChapterId($dto->chapter_id);
        $reservation->setStepNumber($dto->step_number);
        $reservation->setTotalSteps($dto->total_steps);
        $reservation->setForfaitName($dto->forfait_name);
        $reservation->setBiographer($biographer);

        return $reservation;
    }

    public function getReservableServices(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $dbOffers = $tenantEm->getRepository(ServiceOffer::class)->findAll();

        if (!empty($dbOffers)) {
            $slugger = new \Symfony\Component\String\Slugger\AsciiSlugger();
            $result = [];
            foreach ($dbOffers as $offer) {
                $slug = $slugger->slug($offer->getTitre() ?: '')->lower()->toString();
                $result[] = [
                    'id' => $slug ?: (string)$offer->getId(),
                    'label' => $offer->getTitre(),
                    'subtitle' => $offer->getTitreCommentaire() ?: '',
                    'description' => $offer->getDescriptions() ?: $offer->getTitreCommentaire() ?: '',
                ];
            }
            return $result;
        }

        return [
            // Pôle 1 : Art de vivre et accompagnement
            [
                'id' => 'accompagnement-rendez-vous-medicaux',
                'label' => 'Accompagnement aux rendez-vous médicaux',
                'subtitle' => 'Présence attentionnée et transport sécurisé',
                'description' => 'Présence attentionnée, transport et soutien discret lors de consultations médicales, examens ou rendez-vous chez les spécialistes.'
            ],
            [
                'id' => 'accompagnement-rendez-vous-prives',
                'label' => 'Accompagnement aux rendez-vous privés',
                'subtitle' => 'Démarches personnelles et administratives',
                'description' => 'Déplacement accompagné pour vos démarches personnelles, notariales, bancaires ou rendez-vous d\'affaires privées.'
            ],
            [
                'id' => 'accompagnement-sorties-culture-loisirs',
                'label' => 'Accompagnement sorties culturelles & loisirs',
                'subtitle' => 'Culture, expositions et moments choisis',
                'description' => 'Visite guidée d\'expositions, galeries, concerts, théâtres, déjeuners au restaurant ou moments de convivialité choisis.'
            ],
            [
                'id' => 'magasinage-prive-courses',
                'label' => 'Magasinage privé & emplettes personnalisées',
                'subtitle' => 'Achats plaisir et conseils sur mesure',
                'description' => 'Aide et accompagnement sur mesure pour vos achats : mode, librairie, épicerie fine ou marché de quartier.'
            ],
            [
                'id' => 'livraisons-sur-mesure-domicile',
                'label' => 'Livraisons de courses & commandes sur mesure',
                'subtitle' => 'Achats réalisés et rangés à domicile',
                'description' => 'Courses et achats de proximité réalisés pour vous par votre intendante et soigneusement rangés à votre domicile.'
            ],
            [
                'id' => 'soins-bien-etre-domicile',
                'label' => 'Organisation de soins bien-être à domicile',
                'subtitle' => 'Coiffure, esthétique et détente',
                'description' => 'Accueil et supervision de praticiens à domicile : coiffure privée, manucure, soins esthétiques ou massages relaxants.'
            ],
            [
                'id' => 'balades-promenades-douces',
                'label' => 'Balades & promenades douces',
                'subtitle' => 'Grand air et bien-être à votre rythme',
                'description' => 'Moments d\'évasion au grand air dans vos parcs et lieux favoris, avec un rythme adapté, sécurisant et bienveillant.'
            ],
            [
                'id' => 'planification-voyages',
                'label' => 'Planification & préparation de voyages',
                'subtitle' => 'Itinéraires et conciergerie de voyage',
                'description' => 'Élaboration d\'itinéraires, réservations adaptées, gestion des bagages et conciergerie de voyage.'
            ],
            [
                'id' => 'activites-artistiques-creatives',
                'label' => 'Activités artistiques & ateliers créatifs',
                'subtitle' => 'Moments d\'expression et créativité',
                'description' => 'Séances d’éveil et de partage à domicile : peinture, dessin, écriture, composition florale ou musique.'
            ],
            [
                'id' => 'organisation-evenements-familiaux',
                'label' => 'Organisation d\'événements familiaux & réceptions',
                'subtitle' => 'Repas de famille et réceptions privées',
                'description' => 'Préparation, décoration de table, accueil et coordination pour vos repas de famille ou anniversaires à domicile.'
            ],

            // Pôle 2 : Intendance immobilière
            [
                'id' => 'menage-entretien-domicile',
                'label' => 'Ménage & entretien soigné du domicile',
                'subtitle' => 'Propreté et confort de votre intérieur',
                'description' => 'Entretien méticuleux de votre intérieur, dépoussiérage, soin du linge et tenue impeccable de vos espaces de vie.'
            ],
            [
                'id' => 'preparation-repas-domicile',
                'label' => 'Préparation d\'un repas à domicile',
                'subtitle' => 'Cuisine fraîche et repas équilibré',
                'description' => 'Élaboration et confection sur place de plats savoureux et équilibrés, selon vos goûts et préférences culinaires.'
            ],
            [
                'id' => 'accompagnement-aux-courses',
                'label' => 'Accompagnement aux courses',
                'subtitle' => 'Aide pratique et portage des achats',
                'description' => 'Accompagnement pour faire vos courses au marché ou en magasin, avec prise en charge du port des sacs.'
            ],

            // Pôle 3 : Secrétariat privé
            [
                'id' => 'traitement-courrier-classement',
                'label' => 'Traitement du courrier & classement confidentiel',
                'subtitle' => 'Tri, suivi et archivage sécurisé',
                'description' => 'Dépouillement, tri rigoureux, préparation des réponses administratives et archivage confidentiel.'
            ],
            [
                'id' => 'reglement-paiement-factures',
                'label' => 'Pointage & règlement des factures',
                'subtitle' => 'Vérification et suivi des quittances',
                'description' => 'Contrôle attentif de vos factures et quittances, tenue de l\'échéancier et préparation des paiements en toute transparence.'
            ],
            [
                'id' => 'assistance-numerique-demarches',
                'label' => 'Assistance numérique (ordinateur, tablette, smartphone)',
                'subtitle' => 'Pédagogie et démarches dématérialisées',
                'description' => 'Pédagogie et aide individuelle pour naviguer, sécuriser vos outils, communiquer avec vos proches et effectuer vos démarches en ligne.'
            ],
            [
                'id' => 'gestion-agenda-rendez-vous',
                'label' => 'Gestion d\'agenda & prise de rendez-vous',
                'subtitle' => 'Organisation de votre emploi du temps',
                'description' => 'Planification quotidienne, calage et confirmation de vos rendez-vous privés, rappels personnalisés.'
            ],
            [
                'id' => 'coordination-dossier-sante',
                'label' => 'Coordination du dossier de santé',
                'subtitle' => 'Ordonnances, mutuelle et praticiens',
                'description' => 'Organisation de vos pièces médicales, renouvellement d\'ordonnances en pharmacie et lien avec les praticiens de santé.'
            ],

            // Pôle 4 : Mémoire vivante
            [
                'id' => 'memoire-vivante-entretien-cadrage',
                'label' => 'Livre biographique – Entretien découverte & cadrage',
                'subtitle' => 'Premier échange confidentiel et bienveillant',
                'description' => 'Première rencontre confidentielle (à domicile ou à distance) pour faire connaissance, esquisser la trame de votre livre et planifier les étapes d\'écriture.'
            ],

            // Pôle 5 : Premier contact
            [
                'id' => 'echange-confidentiel-bilan',
                'label' => 'Échange confidentiel & bilan personnalisé',
                'subtitle' => 'Définition de votre projet d\'accompagnement',
                'description' => 'Consultation initiale pour étudier l\'ensemble de vos besoins et définir un accompagnement sur mesure.'
            ],
        ];
    }

    public function getAllReservations(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Reservation::class)->findBy([], ['createdAt' => 'DESC']);
    }

    public function findReservation(int $id): ?Reservation
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Reservation::class)->find($id);
    }

    public function confirmReservation(int $id): ?Reservation
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $reservation = $tenantEm->getRepository(Reservation::class)->find($id);
        if (!$reservation) {
            return null;
        }

        $reservation->setStatus('confirmed');
        $tenantEm->flush();

        return $reservation;
    }

    public function getReservationsByBook(string $bookId): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Reservation::class)->findBy(
            ['bookId' => $bookId],
            ['stepNumber' => 'ASC', 'reservationDate' => 'ASC', 'createdAt' => 'ASC']
        );
    }

    public function isSlotBooked(\DateTimeInterface $date, string $slot, ?int $biographerId = null): bool
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $qb = $tenantEm->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.reservationDate = :date')
            ->andWhere('r.reservationSlot = :slot')
            ->andWhere('r.status != :cancelled')
            ->setParameter('date', $date->format('Y-m-d'))
            ->setParameter('slot', $slot)
            ->setParameter('cancelled', 'cancelled');

        if ($biographerId !== null) {
            $qb->andWhere('r.biographer = :biographerId')
               ->setParameter('biographerId', $biographerId);
        }

        return ((int) $qb->getQuery()->getSingleScalarResult()) > 0;
    }

    public function getBookedSlotsByDate(\DateTimeInterface $date, ?int $biographerId = null): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $qb = $tenantEm->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('r.reservationSlot')
            ->where('r.reservationDate = :date')
            ->andWhere('r.status != :cancelled')
            ->andWhere('r.reservationSlot IS NOT NULL')
            ->setParameter('date', $date->format('Y-m-d'))
            ->setParameter('cancelled', 'cancelled');

        if ($biographerId !== null) {
            $qb->andWhere('r.biographer = :biographerId')
               ->setParameter('biographerId', $biographerId);
        }

        $reservations = $qb->getQuery()->getScalarResult();

        return array_values(array_unique(array_filter(array_column($reservations, 'reservationSlot'))));
    }

    public function getBookedSlotsByMonth(string $yearMonth, ?int $biographerId = null): array
    {
        $startDate = new \DateTime($yearMonth . '-01 00:00:00');
        $endDate = (clone $startDate)->modify('last day of this month')->setTime(23, 59, 59);

        $tenantEm = $this->emProvider->getEntityManager();
        $qb = $tenantEm->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('r.reservationDate, r.reservationSlot')
            ->where('r.reservationDate BETWEEN :start AND :end')
            ->andWhere('r.status != :cancelled')
            ->andWhere('r.reservationSlot IS NOT NULL')
            ->setParameter('start', $startDate->format('Y-m-d'))
            ->setParameter('end', $endDate->format('Y-m-d'))
            ->setParameter('cancelled', 'cancelled');

        if ($biographerId !== null) {
            $qb->andWhere('r.biographer = :biographerId')
               ->setParameter('biographerId', $biographerId);
        }

        $reservations = $qb->getQuery()->getResult();

        $result = [];
        foreach ($reservations as $row) {
            $dateStr = $row['reservationDate'] instanceof \DateTimeInterface
                ? $row['reservationDate']->format('Y-m-d')
                : (string)$row['reservationDate'];
            $slot = $row['reservationSlot'];
            if (!isset($result[$dateStr])) {
                $result[$dateStr] = [];
            }
            if (!in_array($slot, $result[$dateStr], true)) {
                $result[$dateStr][] = $slot;
            }
        }

        return $result;
    }

    /**
     * Recherche avancée de réservations avec filtres (biographe, dates, statut, livre)
     */
    public function getReservations(array $filters = []): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $qb = $tenantEm->getRepository(Reservation::class)->createQueryBuilder('r');

        if (!empty($filters['biographerId'])) {
            $qb->andWhere('r.biographer = :biographerId')
               ->setParameter('biographerId', (int)$filters['biographerId']);
        }

        if (!empty($filters['startDate'])) {
            $start = $filters['startDate'] instanceof \DateTimeInterface
                ? $filters['startDate']->format('Y-m-d')
                : (new \DateTime((string)$filters['startDate']))->format('Y-m-d');
            $qb->andWhere('r.reservationDate >= :startDate')
               ->setParameter('startDate', $start);
        }

        if (!empty($filters['endDate'])) {
            $end = $filters['endDate'] instanceof \DateTimeInterface
                ? $filters['endDate']->format('Y-m-d')
                : (new \DateTime((string)$filters['endDate']))->format('Y-m-d');
            $qb->andWhere('r.reservationDate <= :endDate')
               ->setParameter('endDate', $end);
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('r.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['bookId'])) {
            $qb->andWhere('r.bookId = :bookId')
               ->setParameter('bookId', $filters['bookId']);
        }

        $qb->orderBy('r.reservationDate', 'ASC')
           ->addOrderBy('r.reservationSlot', 'ASC')
           ->addOrderBy('r.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }
}
