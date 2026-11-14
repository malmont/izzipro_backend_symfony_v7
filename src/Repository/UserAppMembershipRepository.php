<?php

namespace App\Repository;

use App\Entity\UserAppMembership;
use App\Security\AppFamilies;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<UserAppMembership> */
class UserAppMembershipRepository extends EntityRepository
{
    /**
     * Traces d'usage retenues par la reprise de l'historique (lot M2, `docs/apps-membres.md` § 8), une requête par trace : chacune
     * rend, par compte, la plus ancienne date de la trace. Une adresse n'est pas une trace (elle n'a pas de date et ne prouve pas
     * la boutique) ; une vente de caisse non plus (elle porte le compte du caissier).
     */
    private const TRACES = [
        AppFamilies::MEMOIRES => [
            'signup_living_memory' => 'SELECT id AS user_id, created_at AS at, NULL AS text_version FROM "user" WHERE signup_channel = \'mobile\'',
            'team_invite' => 'SELECT id, created_at, NULL FROM "user" WHERE signup_channel = \'web_invite\'',
            'book_owner' => 'SELECT user_id, MIN(created_at), NULL FROM mv_book WHERE user_id IS NOT NULL GROUP BY user_id',
            'chapter_payment' => 'SELECT user_id, MIN(COALESCE(purchased_at, created_at)), NULL FROM mv_chapter_payment WHERE user_id IS NOT NULL GROUP BY user_id',
            'print_order' => 'SELECT user_id, MIN(created_at), NULL FROM mv_book_print_order WHERE user_id IS NOT NULL GROUP BY user_id',
        ],
        AppFamilies::BOUTIQUE => [
            'signup_mobile_shop' => 'SELECT id, created_at, NULL FROM "user" WHERE signup_channel = \'mobile_shop\'',
            // une commande du compte dont l'origine n'est pas la caisse ; annulée ou remboursée, c'est encore une trace
            'order' => 'SELECT o.user_id_id, MIN(o.order_date), NULL FROM "order" o JOIN order_source s ON s.id = o.order_source_id WHERE o.user_id_id IS NOT NULL AND s.name IN (\'Ecommerce\', \'Mobile_app\') GROUP BY o.user_id_id',
            'review' => 'SELECT user_review_id, MIN(created_at), NULL FROM reviews_product WHERE user_review_id IS NOT NULL GROUP BY user_review_id',
            'subscription' => 'SELECT user_id, MIN(created_at), NULL FROM subscription WHERE user_id IS NOT NULL GROUP BY user_id',
            'checkout_session' => 'SELECT user_id, MIN(created_at), NULL FROM checkout_session WHERE user_id IS NOT NULL GROUP BY user_id',
            // un panier enregistré, rattaché au compte, avec au moins un article
            'cart' => 'SELECT c.user_cart_id, MIN(c.created_at), NULL FROM "Cart" c WHERE c.user_cart_id IS NOT NULL AND EXISTS (SELECT 1 FROM cart_details d WHERE d.carts_id = c.id) GROUP BY c.user_cart_id',
        ],
        AppFamilies::POS => [
            // une fiche de caisse rattachée, ni effacée ni fusionnée, dont le DERNIER consentement account_link est accordé : sa date et sa version
            'pos_link' => 'SELECT c.user_id, k.at, k.text_version FROM pos_customer c JOIN LATERAL (SELECT granted, at, text_version FROM pos_customer_consent WHERE customer_id = c.id AND purpose = \'account_link\' ORDER BY id DESC LIMIT 1) k ON true
                WHERE c.user_id IS NOT NULL AND c.erased_at IS NULL AND c.merged_into_id IS NULL AND k.granted',
        ],
    ];

    /** @return list<UserAppMembership> appartenances d'un compte, de la plus ancienne à la plus récente */
    public function findOfUser(int $userId): array
    {
        return $this->findBy(['userId' => $userId], ['joinedAt' => 'ASC', 'id' => 'ASC']);
    }

    /** @return list<array{id: int, roles: list<string>, deleted: bool, passwordSet: bool}> tous les comptes du site, par identifiant */
    public function backfillAccounts(): array
    {
        return array_map(fn (array $r) => ['id' => (int) $r['id'], 'roles' => array_values((array) json_decode((string) $r['roles'], true)), 'deleted' => (bool) $r['deleted'], 'passwordSet' => (bool) $r['password_set']],
            $this->getEntityManager()->getConnection()->fetchAllAssociative('SELECT id, roles::text AS roles, deleted_at IS NOT NULL AS deleted, password_set FROM "user" ORDER BY id'));
    }

    /** @return list<array{userId: int, app: string, trace: string, at: ?string, textVersion: ?string}> une ligne par compte, famille et trace */
    public function backfillTraces(): array
    {
        $traces = [];
        foreach (self::TRACES as $family => $queries) {
            foreach ($queries as $trace => $sql) {
                foreach ($this->getEntityManager()->getConnection()->fetchAllNumeric($sql) as [$userId, $at, $textVersion]) {
                    $traces[] = ['userId' => (int) $userId, 'app' => $family, 'trace' => $trace, 'at' => $at === null ? null : substr((string) $at, 0, 19), 'textVersion' => $textVersion === null ? null : (string) $textVersion];
                }
            }
        }

        return $traces;
    }

    /** @return array<string, list<string>> famille => noms de ses traces, dans l'ordre du rapport */
    public static function traceNames(): array
    {
        return array_map('array_keys', self::TRACES);
    }

    /** @return array<string, array<int, true>> famille => comptes déjà membres */
    public function membersByFamily(): array
    {
        $members = array_fill_keys(AppFamilies::ALL, []);
        foreach ($this->getEntityManager()->getConnection()->fetchAllNumeric('SELECT app, user_id FROM user_app_membership') as [$app, $userId]) {
            $members[(string) $app][(int) $userId] = true;
        }

        return $members;
    }

    /**
     * Ce que la reprise signale sans y toucher : fiches de caisse rattachées sans consentement `account_link` en vigueur (état d'avant
     * M1), comptes sans mot de passe choisi qui ont des commandes, commandes de comptes sans origine connue (non comptées comme traces).
     *
     * @return array{posLinkedWithoutConsent: int, withoutPasswordWithOrders: int, ordersWithoutSource: int}
     */
    public function backfillAnomalies(): array
    {
        $connection = $this->getEntityManager()->getConnection();

        return [
            'posLinkedWithoutConsent' => (int) $connection->fetchOne('SELECT COUNT(*) FROM pos_customer c WHERE c.user_id IS NOT NULL AND c.erased_at IS NULL AND c.merged_into_id IS NULL
                AND COALESCE((SELECT granted FROM pos_customer_consent WHERE customer_id = c.id AND purpose = \'account_link\' ORDER BY id DESC LIMIT 1), false) = false'),
            'withoutPasswordWithOrders' => (int) $connection->fetchOne('SELECT COUNT(*) FROM "user" u WHERE u.password_set = false AND u.deleted_at IS NULL AND EXISTS (SELECT 1 FROM "order" o WHERE o.user_id_id = u.id)'),
            'ordersWithoutSource' => (int) $connection->fetchOne('SELECT COUNT(*) FROM "order" WHERE user_id_id IS NOT NULL AND order_source_id IS NULL'),
        ];
    }

    /** @return array<int, true> comptes qui ont au moins une adresse (une adresse seule n'est pas une trace : elle est seulement comptée) */
    public function accountsWithAddress(): array
    {
        return array_fill_keys(array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn('SELECT DISTINCT user_adress_id FROM adress WHERE user_adress_id IS NOT NULL')), true);
    }

    /** Date de la reprise de l'historique du site ; null : pas faite */
    public function backfilledAt(): ?string
    {
        $at = $this->getEntityManager()->getConnection()->fetchOne('SELECT app_membership_backfilled_at FROM entreprise ORDER BY id ASC LIMIT 1');

        return $at === false || $at === null ? null : (string) $at;
    }

    /** Marque la reprise faite, à sa première réussite seulement */
    public function markBackfilled(): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE entreprise SET app_membership_backfilled_at = NOW() WHERE app_membership_backfilled_at IS NULL');
    }
}
