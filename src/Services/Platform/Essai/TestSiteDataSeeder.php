<?php

namespace App\Services\Platform\Essai;

use App\Dto\CreateOrderDTO;
use App\Entity\Adress;
use App\Entity\Order;
use App\Entity\User;
use App\LossPrevention\Services\LossExerciseService;
use App\Security\AppFamilies;
use App\Services\AppMembershipService\AppMembershipService;
use App\Services\OrderService\OrderShipmentService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\CancelOrderUseCase;
use App\UseCase\OrderUseCase\CreateOrderUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Les données FICTIVES d'un site d'essai (lot ESSAI-DONNÉES, 17/11/2026, contrat validé) : clients `@example.invalid` (domaine réservé :
 * aucun courriel ne part jamais), commandes passées par le CHEMIN NORMAL du serveur (devis, création, stock décompté, paiement « hors
 * Stripe »), une partie expédiée, une annulée (stock rendu), quelques locations, un exercice des pertes. Les dates de CES seules
 * commandes sont ensuite reculées sur les 60 derniers jours (seule écriture hors chemin normal, écrite dans la fiche). Rejouable : il
 * complète jusqu'aux nombres visés, n'efface et n'écrase rien. Le site courant est déjà choisi (`switchTenant`) par l'appelant, qui a
 * vérifié qu'il s'agit d'un site d'essai (`TestSites`).
 *
 * Hors de ce lot (dit au chef) : le retour d'une commande web exige un paiement Stripe capturé (OrderReturnService) ; la session de
 * caisse en espèces ne peut pas être datée dans le passé (journal de caisse inaltérable, chaîné et daté par le serveur).
 */
class TestSiteDataSeeder
{
    public const CUSTOMERS = 20;
    public const ORDERS = 30;
    public const RENTALS = 3;
    public const SHIPPED = 10;
    public const EMAIL_PREFIX = 'essai-client-';
    public const EMAIL_DOMAIN = '@example.invalid';
    /** Moyen de paiement hors Stripe du référentiel (PayPal) : aucune passerelle n'est appelée par la création d'une commande */
    private const PAYMENT_METHOD = 3;
    private const NAMES = [
        ['Léa', 'Martin'], ['Hugo', 'Bernard'], ['Chloé', 'Dubois'], ['Lucas', 'Thomas'], ['Emma', 'Robert'], ['Louis', 'Richard'],
        ['Manon', 'Petit'], ['Jules', 'Durand'], ['Camille', 'Leroy'], ['Nathan', 'Moreau'], ['Inès', 'Simon'], ['Gabriel', 'Laurent'],
        ['Jade', 'Lefebvre'], ['Raphaël', 'Michel'], ['Alice', 'Garcia'], ['Arthur', 'David'], ['Lina', 'Bertrand'], ['Adam', 'Roux'],
        ['Rose', 'Vincent'], ['Noah', 'Fournier'],
    ];
    private const CITIES = [['Montréal', 'QC', 'H2X 1Y4'], ['Québec', 'QC', 'G1R 2L3'], ['Laval', 'QC', 'H7N 3W9'], ['Sherbrooke', 'QC', 'J1H 1A1'], ['Gatineau', 'QC', 'J8X 3X7']];

    /** @var list<string> */
    private array $report = [];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AppMembershipService $memberships,
        private readonly CreateOrderUseCase $createOrder,
        private readonly OrderShipmentService $shipments,
        private readonly CancelOrderUseCase $cancelOrder,
        private readonly LossExerciseService $exercises,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<string> le compte rendu (une ligne par geste, nombres compris)
     */
    public function seed(bool $dryRun = false): array
    {
        $this->report = [];
        $customers = $this->customers($dryRun);
        if ($dryRun) {
            $this->report[] = sprintf('Commandes : %d à créer au plus (%d existent)', max(0, self::ORDERS - $this->existingOrders()), $this->existingOrders());

            return $this->report;
        }
        $created = $this->orders($customers);
        // les commandes créées sont relues de la base (paiements, lignes) : les objets gardés en mémoire depuis leur création n'ont pas
        // toutes leurs collections remplies
        $this->emProvider->getEntityManager()->clear();
        $this->ship($created);
        $this->cancelOne($created);
        $this->rentals($customers);
        $this->backdate($created);
        $this->exercise();

        return $this->report;
    }

    /** @return list<User> les clients fictifs, créés s'il en manque */
    private function customers(bool $dryRun): array
    {
        $em = $this->emProvider->getEntityManager();
        $out = [];
        $made = 0;
        foreach (self::NAMES as $i => [$first, $last]) {
            $email = sprintf('%s%02d%s', self::EMAIL_PREFIX, $i + 1, self::EMAIL_DOMAIN);
            $user = $em->getRepository(User::class)->findOneByEmailCaseless($email);
            if ($user === null) {
                ++$made;
                if ($dryRun) {
                    continue;
                }
                $joined = new \DateTimeImmutable(sprintf('-%d days', 90 - 3 * $i), new \DateTimeZone('UTC'));
                $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($first)->setLastname($last)
                    ->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET'])->setIsVerified(true)->setSignupChannel(User::SIGNUP_WEB_REGISTER)->setCreatedAt($joined);
                $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(24)))); // inutilisable : un compte fictif ne se connecte pas
                [$city, $province, $zip] = self::CITIES[$i % count(self::CITIES)];
                $address = (new Adress())->setFirstname($first)->setLastname($last)->setFullname("$first $last")->setAddress(sprintf('%d, rue de l\'Exemple', 100 + 10 * $i))
                    ->setCity($city)->setCodepostal($zip)->setProvince($province)->setCountry('CA')->setPhone(sprintf('+1 514 555 01%02d', $i))->setUserAdress($user);
                $user->addAdress($address);
                $user->setPrimaryAddress($address);
                $em->persist($user);
                $em->persist($address);
                $em->flush();
                $this->memberships->grant($user, AppFamilies::BOUTIQUE, AppFamilies::SOURCE_REGISTER, null, $joined, $joined);
            }
            $out[] = $user;
        }
        $this->report[] = sprintf('Clients fictifs : %d %s, %d au total', $made, $dryRun ? 'à créer' : 'créés', count(self::NAMES));

        return $out;
    }

    /**
     * @param list<User> $customers
     * @return list<int> les commandes créées par ce passage
     */
    private function orders(array $customers): array
    {
        $variants = $this->sellableVariants();
        $carrier = $this->fixedCarrier();
        if ($variants === [] || $customers === []) {
            $this->report[] = 'Commandes : aucune (pas d\'article vendable en stock ou pas de client)';

            return [];
        }
        $created = [];
        $failed = [];
        $missing = max(0, self::ORDERS - $this->existingOrders());
        for ($n = 0; $n < $missing; ++$n) {
            $customer = $customers[$n % count($customers)];
            $items = [];
            foreach (array_slice($this->rotate($variants, $n), 0, 1 + $n % 3) as $variantId) {
                $items[] = ['productVariantId' => $variantId, 'quantity' => 1];
            }
            $addressId = $customer->getPrimaryAddress()?->getId();
            try {
                $result = $this->createOrder->execute(new CreateOrderDTO((int) $customer->getId(), 1, self::PAYMENT_METHOD, $addressId, $carrier, 1, $items), $customer);
            } catch (\Throwable $e) {
                $failed[] = $e::class;
                $this->emProvider->getEntityManager()->clear();
                continue;
            }
            if ($result instanceof Order) {
                $created[] = (int) $result->getId();
            } else {
                $failed[] = $result instanceof JsonResponse ? (string) (json_decode((string) $result->getContent(), true)['code'] ?? 'refus') : 'refus';
            }
        }
        $reasons = [];
        foreach (array_count_values($failed) as $reason => $times) {
            $reasons[] = "$reason × $times";
        }
        $this->report[] = sprintf('Commandes web : %d créées par le chemin normal (paiement hors Stripe)%s', count($created), $failed === [] ? '' : sprintf(', %d refusées (%s)', count($failed), implode(', ', $reasons)));

        return $created;
    }

    /** @param list<int> $orderIds */
    private function ship(array $orderIds): void
    {
        $em = $this->emProvider->getEntityManager();
        $shipped = 0;
        foreach (array_slice($orderIds, 0, self::SHIPPED) as $i => $id) {
            $order = $em->find(Order::class, $id);
            if (!$order instanceof Order || $this->shipments->shipBlocker($order) !== null) {
                continue;
            }
            try {
                $this->shipments->ship($order, 'Postes Canada', sprintf('ESSAI%06d', $id), null, false, '');
                ++$shipped;
            } catch (\Throwable $e) {
                $this->logger->warning('[Essai] expédition refusée : ' . $e::class);
            }
        }
        $this->report[] = sprintf('Expéditions : %d commandes marquées expédiées (aucun transporteur appelé, aucun courriel)', $shipped);
    }

    /** @param list<int> $orderIds */
    private function cancelOne(array $orderIds): void
    {
        $candidate = $orderIds[self::SHIPPED] ?? null; // une commande non expédiée
        if ($candidate === null) {
            return;
        }
        $this->emProvider->getEntityManager()->clear();
        $response = $this->cancelOrder->execute($candidate);
        $this->report[] = $response->getStatusCode() < 300 ? 'Annulation : 1 commande annulée (stock rendu)' : 'Annulation : refusée (' . $response->getStatusCode() . ')';
    }

    /** @param list<User> $customers */
    private function rentals(array $customers): void
    {
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $rental = $connection->fetchAssociative('SELECT v.id AS variant, rp.id AS pack FROM product p JOIN product_variant v ON v.product_id = p.id
            JOIN product_categories pc ON pc.product_id = p.id JOIN rental_pack_categories rpc ON rpc.categories_id = pc.categories_id JOIN rental_pack rp ON rp.id = rpc.rental_pack_id
            WHERE p.rental_enabled = TRUE ORDER BY p.id, v.id LIMIT 1');
        if ($rental === false || $customers === []) {
            $this->report[] = 'Locations : aucune (pas de produit louable avec un tarif)';

            return;
        }
        $existing = (int) $connection->fetchOne('SELECT COUNT(*) FROM booking b JOIN order_items oi ON oi.id = b.order_item_id JOIN "order" o ON o.id = oi.order_associated_id JOIN "user" u ON u.id = o.user_id_id WHERE u.email LIKE ?', [self::EMAIL_PREFIX . '%' . self::EMAIL_DOMAIN]);
        $made = 0;
        for ($n = $existing; $n < self::RENTALS; ++$n) { // rejouable : seulement les réservations qui manquent
            $start = (new \DateTimeImmutable(sprintf('+%d days', 7 + 7 * $n)))->setTime(10, 0);
            $item = ['productVariantId' => (int) $rental['variant'], 'quantity' => 1,
                'booking' => ['start' => $start->format('Y-m-d\TH:i:s'), 'end' => $start->modify('+1 day')->format('Y-m-d\TH:i:s'), 'rentalPackId' => (int) $rental['pack']]];
            $customer = $this->emProvider->getEntityManager()->find(User::class, (int) $customers[$n % count($customers)]->getId()); // relu : la mémoire a été vidée
            if (!$customer instanceof User) {
                continue;
            }
            try {
                $result = $this->createOrder->execute(new CreateOrderDTO((int) $customer->getId(), 1, self::PAYMENT_METHOD, $customer->getPrimaryAddress()?->getId(), $this->fixedCarrier(), 1, [$item]), $customer);
                $made += $result instanceof Order ? 1 : 0;
            } catch (\Throwable $e) {
                $this->emProvider->getEntityManager()->clear();
            }
        }
        $this->report[] = sprintf('Locations : %d réservations créées (%d existaient, %d visées)', $made, $existing, self::RENTALS);
    }

    /**
     * Les dates de CES commandes seulement sont reculées sur les 60 derniers jours (commande, statut, expédition, annulation, paiements),
     * pour que les statistiques, la période et « Ma journée » aient de la matière ; seule écriture hors chemin normal du lot.
     *
     * @param list<int> $orderIds
     */
    private function backdate(array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $count = count($orderIds);
        foreach ($orderIds as $i => $id) {
            $days = (int) round(60 * ($count - $i) / $count);
            $shift = sprintf('%d days', $days);
            $connection->executeStatement("UPDATE \"order\" SET order_date = order_date - CAST(? AS INTERVAL), status_updated_at = status_updated_at - CAST(? AS INTERVAL),
                shipped_at = shipped_at - CAST(? AS INTERVAL), canceled_at = canceled_at - CAST(? AS INTERVAL) WHERE id = ?", [$shift, $shift, $shift, $shift, $id]);
            $connection->executeStatement('UPDATE payments SET payment_date = payment_date - CAST(? AS INTERVAL) WHERE order_payment_id = ?', [$shift, $id]);
        }
        $this->report[] = sprintf('Dates : %d commandes réparties sur les 60 derniers jours', $count);
    }

    private function exercise(): void
    {
        $admin = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT id FROM "user" WHERE roles::text LIKE \'%"ROLE_ADMIN"%\' ORDER BY id LIMIT 1');
        if ($admin === false) {
            $this->report[] = 'Exercice des pertes : aucun administrateur sur le site';

            return;
        }
        try {
            $this->exercises->start(['scenario' => 'cash_shortage', 'sendTestEmail' => false], (int) $admin, 'fr');
            $this->report[] = 'Exercice des pertes : 1 constat fictif lancé (effaçable par le gérant)';
        } catch (\Throwable $e) {
            $this->report[] = 'Exercice des pertes : non lancé (' . $e::class . ')';
        }
    }

    private function existingOrders(): int
    {
        return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM "order" o JOIN "user" u ON u.id = o.user_id_id WHERE u.email LIKE ?', [self::EMAIL_PREFIX . '%' . self::EMAIL_DOMAIN]);
    }

    /** @return list<int> variantes vendables en stock (hors location), avec un prix */
    private function sellableVariants(): array
    {
        return array_map('intval', $this->emProvider->getEntityManager()->getConnection()->fetchFirstColumn('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id
            WHERE COALESCE(p.rental_enabled, FALSE) = FALSE AND COALESCE(v.stock_quantity, 0) >= 5 AND COALESCE(v.price, p.price) > 0 ORDER BY v.id LIMIT 12'));
    }

    /** Un transporteur à prix fixe (sans compte EasyPost : aucun tarif demandé dehors), sinon aucun */
    private function fixedCarrier(): ?int
    {
        $id = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT id FROM carrier WHERE carrier_account_id IS NULL ORDER BY id LIMIT 1');

        return $id === false ? null : (int) $id;
    }

    /** @param list<int> $list @return list<int> */
    private function rotate(array $list, int $n): array
    {
        $k = $n % count($list);

        return [...array_slice($list, $k), ...array_slice($list, 0, $k)];
    }
}
