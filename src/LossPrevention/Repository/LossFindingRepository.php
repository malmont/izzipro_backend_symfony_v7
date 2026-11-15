<?php

namespace App\LossPrevention\Repository;

use App\LossPrevention\Entity\LossFinding;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;

/**
 * Constats : créés et complétés par l'analyse, décidés par le gérant. SQL direct ; jamais de cache ; rien ne se supprime, sauf les constats
 * d'EXERCICE (lot EX-1), que le gérant efface lui-même. RÈGLE : tout chiffre réel (comptes, montant en jeu, « nouveaux », courriel, vignette,
 * fenêtre d'une règle par personne, liste par défaut) écarte les constats d'exercice (`exercise = false`). `row()` et `byKey()` rendent la
 * ligne telle quelle : c'est leur appelant qui garde (la fiche et la décision refusent le mauvais genre ; une clé d'exercice commence par
 * `EXERCISE:`, aucune règle ne la produit).
 *
 * @extends EntityRepository<LossFinding>
 */
class LossFindingRepository extends EntityRepository
{
    /**
     * Verrou consultatif des exercices (celui de l'analyse est 7461077). Pris, dans une transaction, par tout ce qui écrit pour un constat
     * d'exercice : lancement, lecture d'une fiche (elle écrit au journal), décision, effacement. L'effacement ne peut donc ni échouer sur
     * une écriture arrivée entre deux de ses suppressions, ni laisser derrière lui une ligne de journal d'un exercice disparu.
     */
    public const EXERCISE_LOCK = 7461078;
    public const SORTS = ['date' => 'store_day DESC, id DESC', 'amount' => 'amount_at_stake DESC NULLS LAST, id DESC'];

    /** @return array<string, mixed>|null */
    public function row(int $id): ?array
    {
        return $this->getEntityManager()->getConnection()->fetchAssociative('SELECT * FROM loss_finding WHERE id = ?', [$id]) ?: null;
    }

    /** @return array<string, mixed>|null */
    public function byKey(string $dedupKey): ?array
    {
        return $this->getEntityManager()->getConnection()->fetchAssociative('SELECT * FROM loss_finding WHERE dedup_key = ?', [$dedupKey]) ?: null;
    }

    /**
     * Constat « par personne » d'une règle pour un compte dont la fenêtre recoupe [`$since`, `$until`]. Bornée des deux côtés : en rejouant une
     * entrée ancienne, on ne doit jamais retrouver (et compléter à tort) un constat né d'une fenêtre postérieure.
     *
     * @return array<string, mixed>|null
     */
    public function latestForPerson(string $ruleCode, int $userId, \DateTimeImmutable $since, \DateTimeImmutable $until): ?array
    {
        return $this->getEntityManager()->getConnection()->fetchAssociative(
            "SELECT * FROM loss_finding WHERE exercise = false AND rule_code = ? AND variant = 'person' AND subject_type = 'user_window' AND subject_id = ? AND occurred_to >= ? AND occurred_from <= ? ORDER BY id DESC LIMIT 1",
            [$ruleCode, $userId, $since->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s')]) ?: null;
    }

    /**
     * Crée un constat (numéro suivant du site). L'appelant tient le verrou de l'analyse : un seul écrivain à la fois.
     *
     * Un constat d'EXERCICE (`exercise` vrai, `number` fourni par l'appelant) porte un numéro NÉGATIF, l'opposé du numéro de l'exercice : il ne
     * consomme ni ne fait réutiliser aucun numéro de vrai constat (les vrais numéros se suivent parmi les vrais constats seulement).
     *
     * @param array<string, mixed> $f colonnes du constat (facts et on_duty en tableaux)
     * @return int identifiant
     */
    public function create(array $f): int
    {
        $conn = $this->getEntityManager()->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $exercise = (bool) ($f['exercise'] ?? false);
        $conn->insert('loss_finding', [
            'number' => $exercise ? -abs((int) $f['number']) : (int) $conn->fetchOne('SELECT COALESCE(MAX(number), 0) + 1 FROM loss_finding WHERE exercise = false'),
            'exercise' => $exercise ? 'true' : 'false',
            'rule_code' => $f['rule_code'], 'variant' => $f['variant'], 'dedup_key' => $f['dedup_key'], 'status' => LossFinding::OPEN,
            'store_day' => $f['store_day'], 'occurred_from' => $f['occurred_from'], 'occurred_to' => $f['occurred_to'],
            'cash_register_id' => $f['cash_register_id'], 'session_id' => $f['session_id'], 'amount_at_stake' => $f['amount_at_stake'], 'currency' => $f['currency'],
            'subject_type' => $f['subject_type'], 'subject_id' => $f['subject_id'], 'facts' => json_encode((object) $f['facts']), 'on_duty' => json_encode(array_values($f['on_duty'])),
            'backfill' => $f['backfill'] ? 'true' : 'false', 'last_evidence_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) $conn->lastInsertId();
    }

    /**
     * Complète un constat existant : chiffres, fenêtre, personnes en poste, montant. L'état et la décision ne changent jamais ici.
     *
     * @param array<string, mixed> $facts
     * @param list<array{id: int, role: string}> $onDuty
     */
    public function complete(int $id, array $facts, array $onDuty, ?int $amountAtStake, \DateTimeImmutable $occurredTo, bool $newEvidence, bool $recent = false): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        // `$recent` : la preuve ajoutée vient d'un fait postérieur à la première analyse ; un constat né de la reprise cesse alors d'en être une
        // (il redevient « nouveau » s'il n'a jamais été consulté)
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE loss_finding SET facts = :facts, on_duty = :duty, amount_at_stake = :amount, occurred_to = GREATEST(occurred_to, :to), updated_at = :now'
            . ($newEvidence ? ', last_evidence_at = :now' : '') . ($newEvidence && $recent ? ', backfill = false' : '') . ' WHERE id = :id',
            ['facts' => json_encode((object) $facts), 'duty' => json_encode(array_values($onDuty)), 'amount' => $amountAtStake, 'to' => $occurredTo->format('Y-m-d H:i:s'), 'now' => $now, 'id' => $id]
        );
    }

    /** Première consultation par un administrateur : éteint « nouveau » */
    public function markSeen(int $id, int $userId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE loss_finding SET seen_at = ?, seen_by = ? WHERE id = ? AND seen_at IS NULL',
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $userId, $id]);
    }

    public function decide(int $id, string $status, ?string $note, int $userId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $open = $status === LossFinding::OPEN;
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE loss_finding SET status = ?, decision_note = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ?',
            [$status, $note, $open ? null : $userId, $open ? null : $now, $now, $id]);
    }

    /**
     * @param array{statuses?: list<string>, rule?: ?string, from?: ?string, to?: ?string, cashRegisterId?: ?int, isNew?: ?bool} $filters journées du magasin (Y-m-d) ;
     *        la recherche ne rend que de VRAIS constats (ceux d'exercice sortent par `exercises()`)
     * @return array{0: list<array<string, mixed>>, 1: int} lignes de la page et total
     */
    public function search(array $filters, string $sort, int $page, int $limit): array
    {
        [$where, $params, $types] = self::where($filters);
        $conn = $this->getEntityManager()->getConnection();
        $total = (int) $conn->fetchOne("SELECT COUNT(*) FROM loss_finding $where", $params, $types);
        $rows = $conn->fetchAllAssociative("SELECT * FROM loss_finding $where ORDER BY " . (self::SORTS[$sort] ?? self::SORTS['date']) . ' LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit), $params, $types);

        return [$rows, $total];
    }

    /**
     * Constats NÉS après un constat donné et encore nouveaux (jamais ouverts, hors reprise, non décidés), pour le courriel de résumé : leur
     * nombre, leur montant en jeu, et le plus grand identifiant parmi EUX (la borne que le courriel posera). Deux nombres : aucun nom.
     *
     * UNE seule instruction, sur les mêmes lignes : la borne est le dernier constat ANNONCÉ. Lue à part sur toute la table, elle aurait pu
     * dépasser un constat né entre les deux lectures (une analyse lancée par la synthèse pendant le passage du minuteur), jamais annoncé.
     * Un constat d'exercice n'est jamais annoncé et ne fait pas avancer la borne.
     *
     * `$renewedIds` : constats redevenus « nouveaux » que le même courriel annonce à part (`renewedAmong`) ; ils ne sont pas comptés ici,
     * mais la borne les couvre (sinon le courriel suivant les annoncerait une seconde fois, comme des constats nés depuis).
     *
     * @param list<int> $renewedIds
     * @return array{count: int, amount: int, lastId: int} `lastId` vaut 0 quand aucun constat n'est au-dessus de la borne
     */
    public function bornAfter(int $findingId, array $renewedIds = []): array
    {
        $apart = $renewedIds === [] ? 'true' : 'id NOT IN (' . implode(',', array_map('intval', $renewedIds)) . ')';
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            "SELECT COUNT(*) FILTER (WHERE $apart) AS n, COALESCE(SUM(amount_at_stake) FILTER (WHERE $apart), 0) AS amount, COALESCE(MAX(id), 0) AS last"
            . " FROM loss_finding WHERE id > ? AND exercise = false AND seen_at IS NULL AND backfill = false AND status = 'open'", [$findingId]) ?: [];

        return ['count' => (int) ($row['n'] ?? 0), 'amount' => (int) ($row['amount'] ?? 0), 'lastId' => (int) ($row['last'] ?? 0)];
    }

    /**
     * Parmi des constats redevenus « nouveaux » (une preuve récente a complété un constat de reprise), ceux qui le sont encore : jamais
     * ouverts, non décidés, hors exercice. Leur nombre et leur montant en jeu, pour le courriel de résumé.
     *
     * @param list<int> $ids
     * @return array{count: int, amount: int}
     */
    public function renewedAmong(array $ids): array
    {
        if ($ids === []) {
            return ['count' => 0, 'amount' => 0];
        }
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS n, COALESCE(SUM(amount_at_stake), 0) AS amount FROM loss_finding WHERE id IN (' . implode(',', array_map('intval', $ids)) . ")"
            . " AND exercise = false AND seen_at IS NULL AND backfill = false AND status = 'open'") ?: [];

        return ['count' => (int) ($row['n'] ?? 0), 'amount' => (int) ($row['amount'] ?? 0)];
    }

    /**
     * Comptes de la synthèse sur des journées du magasin.
     *
     * @return array{byStatus: array<string, int>, new: int, amountAtStake: int, byRule: list<array<string, mixed>>, byRegister: list<array<string, mixed>>}
     */
    public function counts(string $from, string $to): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $p = ['from' => $from, 'to' => $to];
        $active = "status IN ('open', 'watch')";
        $real = 'exercise = false'; // aucun chiffre de la synthèse ne compte un constat d'exercice
        $byStatus = array_fill_keys(LossFinding::STATUSES, 0);
        foreach ($conn->fetchAllKeyValue("SELECT status, COUNT(*) FROM loss_finding WHERE $real AND store_day BETWEEN :from AND :to GROUP BY status", $p) as $s => $n) {
            $byStatus[(string) $s] = (int) $n;
        }

        return [
            'byStatus' => $byStatus,
            'new' => (int) $conn->fetchOne("SELECT COUNT(*) FROM loss_finding WHERE $real AND store_day BETWEEN :from AND :to AND seen_at IS NULL AND backfill = false", $p),
            'amountAtStake' => (int) $conn->fetchOne("SELECT COALESCE(SUM(amount_at_stake), 0) FROM loss_finding WHERE $real AND store_day BETWEEN :from AND :to AND $active", $p),
            'byRule' => $conn->fetchAllAssociative("SELECT rule_code AS code, COUNT(*) FILTER (WHERE $active) AS open, COUNT(*) AS total FROM loss_finding WHERE $real AND store_day BETWEEN :from AND :to GROUP BY rule_code ORDER BY rule_code", $p),
            'byRegister' => $conn->fetchAllAssociative("SELECT f.cash_register_id AS id, r.name, COUNT(*) FILTER (WHERE f.$active) AS open, COUNT(*) AS total FROM loss_finding f LEFT JOIN cash_register r ON r.id = f.cash_register_id"
                . ' WHERE f.exercise = false AND f.store_day BETWEEN :from AND :to AND f.cash_register_id IS NOT NULL GROUP BY f.cash_register_id, r.name ORDER BY f.cash_register_id', $p),
        ];
    }

    /** Constats encore à traiter (nouveaux ou à suivre), toutes dates confondues */
    public function activeCount(): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne("SELECT COUNT(*) FROM loss_finding WHERE exercise = false AND status IN ('open', 'watch')");
    }

    /** Nombre de constats d'exercice du site (plafonné par `LossExerciseService::LIMIT`) */
    public function exerciseCount(): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE exercise');
    }

    /** @return list<array<string, mixed>> les constats d'exercice du site, du plus récent au plus ancien */
    public function exercises(): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative('SELECT * FROM loss_finding WHERE exercise ORDER BY id DESC');
    }

    /**
     * Efface TOUS les constats d'exercice du site, avec leurs preuves et leurs lignes de journal. UNE transaction, la porte
     * `loss.purge = 'exercise'`, et un ORDRE imposé : preuves, puis journal, puis constats. Les déclencheurs de la base lisent la marque du
     * constat pour laisser passer la suppression d'une preuve ou d'une ligne de journal : le constat doit encore exister. La base refuse
     * elle-même tout ce qui n'appartient pas à un constat d'exercice, même si ce code se trompait.
     *
     * @return int nombre de constats d'exercice effacés
     */
    public function deleteExercises(): int
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->beginTransaction();
        try {
            $conn->executeStatement('SELECT pg_advisory_xact_lock(' . self::EXERCISE_LOCK . ')'); // personne n'écrit pour un exercice pendant l'effacement
            $conn->executeStatement("SET LOCAL loss.purge = 'exercise'");
            $conn->executeStatement('DELETE FROM loss_evidence WHERE finding_id IN (SELECT id FROM loss_finding WHERE exercise)');
            $conn->executeStatement('DELETE FROM loss_journal WHERE finding_id IN (SELECT id FROM loss_finding WHERE exercise)');
            $deleted = (int) $conn->executeStatement('DELETE FROM loss_finding WHERE exercise');
            $conn->commit();

            return $deleted;
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>} */
    private static function where(array $filters): array
    {
        $sql = ['exercise = false'];
        $params = [];
        $types = [];
        if (($filters['statuses'] ?? []) !== []) {
            $sql[] = 'status IN (:statuses)';
            $params['statuses'] = $filters['statuses'];
            $types['statuses'] = ArrayParameterType::STRING;
        }
        if (($filters['rule'] ?? null) !== null) {
            $sql[] = 'rule_code = :rule';
            $params['rule'] = $filters['rule'];
        }
        if (($filters['from'] ?? null) !== null) {
            $sql[] = 'store_day >= :from';
            $params['from'] = $filters['from'];
        }
        if (($filters['to'] ?? null) !== null) {
            $sql[] = 'store_day <= :to';
            $params['to'] = $filters['to'];
        }
        if (($filters['cashRegisterId'] ?? null) !== null) {
            $sql[] = 'cash_register_id = :register';
            $params['register'] = $filters['cashRegisterId'];
        }
        if (($filters['isNew'] ?? null) !== null) {
            $sql[] = $filters['isNew'] ? '(seen_at IS NULL AND backfill = false)' : '(seen_at IS NOT NULL OR backfill = true)';
        }

        return [$sql === [] ? '' : 'WHERE ' . implode(' AND ', $sql), $params, $types];
    }
}
