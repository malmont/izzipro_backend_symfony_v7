<?php

namespace App\MemoiresVivantes\Repository;

use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Lectures agrégées par utilisateur pour la console d'administration (16/10/2026) : livres et chapitres, paiements,
 * appels IA. SQL natif sur la base du site courant (connexion de l'EntityManager du tenant), une requête par
 * famille et par page, jamais une requête par utilisateur.
 */
class UserActivityStatsRepository
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /**
     * Identifiants des utilisateurs de la population mobile (inscription mobile ou au moins un livre mobile,
     * administrateurs exclus), filtrés et paginés
     *
     * @return array{ids: list<int>, total: int}
     */
    public function mobileUserIds(string $search, int $page, int $perPage): array
    {
        $where = 'WHERE (u.signup_channel = \'mobile\' OR EXISTS (SELECT 1 FROM mv_book b WHERE b.user_id = u.id AND b.channel = \'mobile\'))'
            . ' AND u.roles::text NOT LIKE \'%ROLE_ADMIN%\'';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (u.email ILIKE :like OR u.firstname ILIKE :like OR u.lastname ILIKE :like)';
            $params['like'] = '%' . addcslashes($search, '%_\\') . '%';
        }
        $c = $this->connection();
        $total = (int) $c->fetchOne('SELECT COUNT(*) FROM "user" u ' . $where, $params);
        $ids = $c->fetchFirstColumn('SELECT u.id FROM "user" u ' . $where . ' ORDER BY u.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);

        return ['ids' => array_map('intval', $ids), 'total' => $total];
    }

    /**
     * @param list<int>|null $userIds null = tous les utilisateurs ayant au moins un livre
     * @return array<int, array{books: int, mobileBooks: int, chaptersTotal: int, chaptersWritten: int, lastActivity: ?string}>
     */
    public function bookStats(?array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $sql = 'SELECT b.user_id, COUNT(DISTINCT b.id) AS books, COUNT(DISTINCT CASE WHEN b.channel = \'mobile\' THEN b.id END) AS mobile_books,'
            . ' COUNT(c.id) AS chapters_total,'
            . ' COUNT(CASE WHEN c.generation_status = \'completed\' AND COALESCE(NULLIF(c.content_final, \'\'), NULLIF(c.content_generated, \'\')) IS NOT NULL THEN 1 END) AS chapters_written,'
            . ' MAX(GREATEST(b.updated_at, c.updated_at)) AS last_activity'
            . ' FROM mv_book b LEFT JOIN mv_chapter c ON c.book_id = b.id'
            . ($userIds !== null ? ' WHERE b.user_id IN (:ids)' : '')
            . ' GROUP BY b.user_id';
        $rows = $this->connection()->fetchAllAssociative($sql, $userIds !== null ? ['ids' => $userIds] : [], $userIds !== null ? ['ids' => ArrayParameterType::INTEGER] : []);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = ['books' => (int) $r['books'], 'mobileBooks' => (int) $r['mobile_books'], 'chaptersTotal' => (int) $r['chapters_total'], 'chaptersWritten' => (int) $r['chapters_written'], 'lastActivity' => $r['last_activity']];
        }

        return $out;
    }

    /**
     * Paiements de livre (Stripe Checkout, portés par mv_book) et commandes d'impression (mv_book_print_order)
     *
     * @param list<int> $userIds
     * @return array<int, array{count: int, totalAmount: float, currency: string, lastPaidAt: ?string}>
     */
    public function paymentStats(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $sql = 'SELECT user_id, SUM(n) AS n, SUM(total) AS total, MAX(last_at) AS last_at, MAX(currency) AS currency FROM ('
            . ' SELECT user_id, COUNT(*) AS n, COALESCE(SUM(payment_amount), 0) AS total, MAX(paid_at) AS last_at, MAX(payment_currency) AS currency FROM mv_book WHERE payment_status = \'paid\' AND user_id IN (:ids) GROUP BY user_id'
            . ' UNION ALL'
            . ' SELECT user_id, COUNT(*) AS n, COALESCE(SUM(total_cost), 0) AS total, MAX(created_at) AS last_at, MAX(currency) AS currency FROM mv_book_print_order WHERE user_id IN (:ids) GROUP BY user_id'
            . ') p GROUP BY user_id';
        $out = [];
        foreach ($this->connection()->fetchAllAssociative($sql, ['ids' => $userIds], ['ids' => ArrayParameterType::INTEGER]) as $r) {
            $out[(int) $r['user_id']] = ['count' => (int) $r['n'], 'totalAmount' => round((float) $r['total'], 2), 'currency' => strtolower((string) ($r['currency'] ?: 'eur')), 'lastPaidAt' => $r['last_at']];
        }

        return $out;
    }

    /**
     * Cumul des appels IA aboutis par utilisateur et par type, avec le dernier appel
     *
     * @param list<int> $userIds
     * @return array<int, array{kinds: array<string, int>, lastCallAt: ?string}>
     */
    public function aiTotals(array $userIds, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        if ($userIds === []) {
            return [];
        }
        $sql = 'SELECT user_id, kind, COUNT(*) AS n, MAX(created_at) AS last_at FROM mv_ai_usage WHERE user_id IN (:ids)';
        $params = ['ids' => $userIds];
        $types = ['ids' => ArrayParameterType::INTEGER];
        if ($from !== null && $to !== null) {
            $sql .= ' AND created_at >= :from AND created_at < :to';
            $params['from'] = $from->format('Y-m-d H:i:s');
            $params['to'] = $to->format('Y-m-d H:i:s');
        }
        $out = [];
        foreach ($this->connection()->fetchAllAssociative($sql . ' GROUP BY user_id, kind', $params, $types) as $r) {
            $id = (int) $r['user_id'];
            $out[$id] ??= ['kinds' => [], 'lastCallAt' => null];
            $out[$id]['kinds'][$r['kind']] = (int) $r['n'];
            if ($out[$id]['lastCallAt'] === null || $r['last_at'] > $out[$id]['lastCallAt']) {
                $out[$id]['lastCallAt'] = $r['last_at'];
            }
        }

        return $out;
    }

    /**
     * Historique par jour (fuseau du site) des appels IA d'un utilisateur sur les N derniers jours
     *
     * @return list<array{date: string, transcriptions: int, improvements: int, generations: int}>
     */
    public function aiHistory(int $userId, string $timezone, int $days): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT (created_at AT TIME ZONE \'UTC\' AT TIME ZONE :tz)::date AS d, kind, COUNT(*) AS n FROM mv_ai_usage WHERE user_id = :id AND created_at >= :since GROUP BY 1, 2 ORDER BY 1',
            ['tz' => $timezone, 'id' => $userId, 'since' => (new \DateTimeImmutable('now'))->modify(sprintf('-%d days', $days))->format('Y-m-d H:i:s')]
        );
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['d']] ??= ['date' => $r['d'], 'transcriptions' => 0, 'improvements' => 0, 'generations' => 0];
            $key = ['transcription' => 'transcriptions', 'improvement' => 'improvements', 'generation' => 'generations'][$r['kind']] ?? null;
            if ($key !== null) {
                $byDay[$r['d']][$key] = (int) $r['n'];
            }
        }

        return array_values($byDay);
    }

    private function connection(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
