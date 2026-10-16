<?php

namespace App\MemoiresVivantes\Repository;

use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Registre des paiements de la console d'administration (16/10/2026) : une lecture SQL qui réunit les achats de
 * chapitre (mv_chapter_payment), les impressions commandées depuis l'application (mv_book_print_order avec un
 * paiement Stripe) et, sur demande, les livres payés par lien Stripe (mv_book). Lecture seule.
 */
class PaymentLedgerRepository
{
    public const KINDS = ['chapter', 'print', 'book'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /**
     * @param list<string> $kinds
     * @param list<string> $statuses
     * @param list<string> $stores
     * @return list<array<string, mixed>> lignes triées par date de paiement décroissante (toutes, pour les totaux ; la page est découpée ensuite)
     */
    public function find(\DateTimeImmutable $from, \DateTimeImmutable $to, array $kinds, array $statuses, array $stores, string $search): array
    {
        $union = <<<'SQL'
SELECT 'chapter' AS kind, 'chapter-payment-' || cp.id::text AS id, cp.status::varchar AS status, cp.amount::numeric AS amount,
       LOWER(COALESCE(cp.currency, 'eur'))::varchar AS currency, COALESCE(cp.assigned_at, cp.purchased_at, cp.created_at) AS paid_at,
       cp.refunded_at AS refunded_at, cp.refunded_amount::numeric AS refunded_amount, cp.store::varchar AS store,
       (cp.store || ':' || cp.transaction_id)::varchar AS payment_ref, cp.environment::varchar AS environment,
       u.id AS user_id, u.email::varchar AS email, u.firstname::varchar AS firstname, u.lastname::varchar AS lastname,
       b.id AS book_id, b.title::varchar AS book_title, b.channel::varchar AS book_channel,
       c.id AS chapter_id, c.title::varchar AS chapter_title, NULL::int AS print_quantity, NULL::uuid AS print_order_id, NULL::varchar AS lulu_status
FROM mv_chapter_payment cp
JOIN "user" u ON u.id = cp.user_id
LEFT JOIN mv_book b ON b.id = cp.book_id
LEFT JOIN mv_chapter c ON c.id = cp.chapter_id
UNION ALL
SELECT 'print', o.id::text, COALESCE(o.payment_status, 'paid')::varchar, COALESCE(o.charged_amount, o.total_cost)::numeric,
       LOWER(COALESCE(o.charged_currency, o.currency, 'eur'))::varchar, o.created_at,
       o.refunded_at, o.refunded_amount::numeric, 'stripe'::varchar, o.stripe_payment_intent_id::varchar, NULL::varchar,
       u.id, u.email::varchar, u.firstname::varchar, u.lastname::varchar,
       b.id, b.title::varchar, b.channel::varchar,
       NULL::uuid, NULL::varchar, o.quantity::int, o.id, o.status::varchar
FROM mv_book_print_order o
JOIN "user" u ON u.id = o.user_id
LEFT JOIN mv_book b ON b.id = o.book_id
WHERE o.payment_status IS NOT NULL
UNION ALL
SELECT 'book', 'book-payment-' || b.id::text, 'paid'::varchar, b.payment_amount::numeric,
       LOWER(COALESCE(b.payment_currency, 'eur'))::varchar, b.paid_at,
       NULL::timestamp, NULL::numeric, 'stripe'::varchar, b.stripe_session_id::varchar, NULL::varchar,
       u.id, u.email::varchar, u.firstname::varchar, u.lastname::varchar,
       b.id, b.title::varchar, b.channel::varchar,
       NULL::uuid, NULL::varchar, NULL::int, NULL::uuid, NULL::varchar
FROM mv_book b
JOIN "user" u ON u.id = b.user_id
WHERE b.payment_status = 'paid'
SQL;
        $where = ['p.kind IN (:kinds)', 'p.paid_at >= :from', 'p.paid_at < :to'];
        $params = ['kinds' => $kinds, 'from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        $types = ['kinds' => ArrayParameterType::STRING];
        if ($statuses !== []) {
            $where[] = 'p.status IN (:statuses)';
            $params['statuses'] = $statuses;
            $types['statuses'] = ArrayParameterType::STRING;
        }
        if ($stores !== []) {
            $where[] = 'p.store IN (:stores)';
            $params['stores'] = $stores;
            $types['stores'] = ArrayParameterType::STRING;
        }
        if ($search !== '') {
            $where[] = '(p.email ILIKE :like OR p.firstname ILIKE :like OR p.lastname ILIKE :like)';
            $params['like'] = '%' . addcslashes($search, '%_\\') . '%';
        }

        return $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT * FROM (' . $union . ') p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.paid_at DESC NULLS LAST, p.id DESC',
            $params,
            $types
        );
    }
}
