<?php

namespace App\LossPrevention\Repository;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossDevice;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Entity\LossPrintJob;
use App\LossPrevention\Entity\LossPrintJobLine;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityRepository;

/**
 * Travaux d'impression des puces et mot de passe d'accès rangé chiffré (lot L3-c, `docs/prevention-pertes.md` § 16.20). Tout est
 * lu et écrit en SQL, dans la transaction de l'appelant. Le chiffre du mot de passe ne sort d'ici que vers `LossPrintService`.
 *
 * @extends EntityRepository<LossPrintJob>
 */
class LossPrintRepository extends EntityRepository
{
    /** Verrou consultatif de transaction : une seule création de mot de passe à la fois sur un site */
    public const SECRET_LOCK = 7461085;

    private const JOB = 'j.id, j.box_id, j.device_id, j.status, j.with_price, j.exercise, j.secret_rank, j.created_by, j.created_at, j.finished_at, j.cancelled_at';

    // ----- Le mot de passe d'accès -----

    public function lockSecrets(): void
    {
        $this->db()->executeStatement('SELECT pg_advisory_xact_lock(' . self::SECRET_LOCK . ')');
    }

    /** @return array{rank: int, key_rank: int, cipher: string}|null le mot de passe en vigueur (le plus grand rang) */
    public function currentSecret(): ?array
    {
        $row = $this->db()->fetchAssociative('SELECT rank, key_rank, cipher FROM loss_tag_secret ORDER BY rank DESC LIMIT 1');

        return $row === false ? null : ['rank' => (int) $row['rank'], 'key_rank' => (int) $row['key_rank'], 'cipher' => (string) $row['cipher']];
    }

    /** @return array<int, array{rank: int, key_rank: int, cipher: string}> tous les mots de passe rangés, par rang (un seul aujourd'hui) */
    public function secrets(): array
    {
        $out = [];
        foreach ($this->db()->fetchAllAssociative('SELECT rank, key_rank, cipher FROM loss_tag_secret ORDER BY rank') as $row) {
            $out[(int) $row['rank']] = ['rank' => (int) $row['rank'], 'key_rank' => (int) $row['key_rank'], 'cipher' => (string) $row['cipher']];
        }

        return $out;
    }

    public function insertSecret(int $rank, int $keyRank, string $cipher, string $now): void
    {
        $this->db()->executeStatement('INSERT INTO loss_tag_secret (rank, key_rank, cipher, created_at) VALUES (?, ?, ?, ?)', [$rank, $keyRank, $cipher, $now]);
    }

    // ----- Les imprimantes -----

    /**
     * Les imprimantes à puce en service (ni retirées, ni d'un boîtier révoqué), de ce monde (exercice ou non).
     *
     * @return list<array<string, mixed>>
     */
    public function printers(bool $exercise, ?int $deviceId = null): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT d.id, d.box_id, d.serial, d.model, d.status, d.last_seen_at, b.name AS box_name, b.status AS box_status, b.last_seen_at AS box_seen_at FROM loss_device d JOIN loss_box b ON b.id = d.box_id'
            . ' WHERE d.kind = :kind AND d.status <> :retired AND b.status <> :revoked AND b.exercise = CAST(:exercise AS boolean)' . ($deviceId !== null ? ' AND d.id = :device' : '') . ' ORDER BY d.id',
            ['kind' => LossDevice::PRINTER, 'retired' => LossDevice::RETIRED, 'revoked' => LossBox::REVOKED, 'exercise' => $exercise ? 'true' : 'false'] + ($deviceId !== null ? ['device' => $deviceId] : [])
        );
    }

    // ----- Les travaux -----

    public function openJobCount(bool $exercise): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM loss_print_job WHERE exercise = CAST(:exercise AS boolean) AND status IN (:open)',
            ['exercise' => $exercise ? 'true' : 'false', 'open' => LossPrintJob::OPEN], ['open' => ArrayParameterType::STRING]);
    }

    public function insertJob(int $boxId, int $deviceId, bool $withPrice, bool $exercise, ?int $secretRank, ?int $userId, string $now): int
    {
        return (int) $this->db()->fetchOne(
            'INSERT INTO loss_print_job (box_id, device_id, status, with_price, exercise, secret_rank, created_by, created_at) VALUES (:box, :device, :status, CAST(:price AS boolean), CAST(:exercise AS boolean), :rank, :user, :now) RETURNING id',
            ['box' => $boxId, 'device' => $deviceId, 'status' => LossPrintJob::WAITING, 'price' => $withPrice ? 'true' : 'false', 'exercise' => $exercise ? 'true' : 'false', 'rank' => $secretRank, 'user' => $userId, 'now' => $now]
        );
    }

    public function insertLine(int $jobId, int $position, int $pieceId, string $identifier): int
    {
        return (int) $this->db()->fetchOne('INSERT INTO loss_print_job_line (job_id, position, piece_id, identifier) VALUES (?, ?, ?, ?) RETURNING id', [$jobId, $position, $pieceId, $identifier]);
    }

    /**
     * Combien de lignes chaque pièce a déjà reçues, tous travaux confondus : le rang de sa prochaine étiquette.
     *
     * @param list<int> $pieceIds
     *
     * @return array<int, int> par pièce (absente : aucune)
     */
    public function copiesOf(array $pieceIds): array
    {
        if ($pieceIds === []) {
            return [];
        }

        return array_map('intval', $this->db()->fetchAllKeyValue('SELECT piece_id, COUNT(*) FROM loss_print_job_line WHERE piece_id IN (?) GROUP BY piece_id', [$pieceIds], [ArrayParameterType::INTEGER]));
    }

    /**
     * @param list<int> $pieceIds
     *
     * @return list<int> celles qui attendent déjà dans un travail
     */
    public function waitingPieces(array $pieceIds): array
    {
        if ($pieceIds === []) {
            return [];
        }

        return array_map('intval', $this->db()->fetchFirstColumn('SELECT piece_id FROM loss_print_job_line WHERE status = ? AND piece_id IN (?) ORDER BY piece_id',
            [LossPrintJobLine::WAITING, $pieceIds], [\Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::INTEGER]));
    }

    /** @return array<string, mixed>|null un travail de ce monde, avec son imprimante */
    public function job(int $id, bool $exercise, bool $lock = false): ?array
    {
        return $this->db()->fetchAssociative('SELECT ' . self::JOB . ' FROM loss_print_job j WHERE j.id = ? AND j.exercise = CAST(? AS boolean)' . ($lock ? ' FOR UPDATE' : ''), [$id, $exercise ? 'true' : 'false']) ?: null;
    }

    /**
     * Les travaux de ce monde, le plus récent d'abord, avec le compte de leurs lignes par état.
     *
     * @return array{0: list<array<string, mixed>>, 1: int} les travaux de la page et leur nombre total
     */
    public function jobs(bool $exercise, int $page, int $limit): array
    {
        $params = ['exercise' => $exercise ? 'true' : 'false'];
        $rows = $this->db()->fetchAllAssociative(
            'SELECT ' . self::JOB . ', d.serial, d.model, b.name AS box_name,'
            . ' (SELECT COUNT(*) FROM loss_print_job_line l WHERE l.job_id = j.id) AS lines,'
            . " (SELECT COUNT(*) FROM loss_print_job_line l WHERE l.job_id = j.id AND l.status = 'written') AS written,"
            . " (SELECT COUNT(*) FROM loss_print_job_line l WHERE l.job_id = j.id AND l.status = 'failed') AS failed,"
            . " (SELECT COUNT(*) FROM loss_print_job_line l WHERE l.job_id = j.id AND l.status = 'waiting') AS waiting"
            . ' FROM loss_print_job j JOIN loss_device d ON d.id = j.device_id JOIN loss_box b ON b.id = j.box_id WHERE j.exercise = CAST(:exercise AS boolean) ORDER BY j.id DESC LIMIT '
            . max(1, $limit) . ' OFFSET ' . max(0, ($page - 1) * $limit), $params);

        return [$rows, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM loss_print_job j WHERE j.exercise = CAST(:exercise AS boolean)', $params)];
    }

    /** @return array<string, mixed>|null la ligne de liste d'un seul travail (mêmes colonnes que `jobs`) */
    public function jobRow(int $id, bool $exercise): ?array
    {
        return $this->db()->fetchAssociative(
            'SELECT ' . self::JOB . ', d.serial, d.model, b.name AS box_name,'
            . ' (SELECT COUNT(*) FROM loss_print_job_line l WHERE l.job_id = j.id) AS lines,'
            . " (SELECT COUNT(*) FROM loss_print_job_line l WHERE l.job_id = j.id AND l.status = 'written') AS written,"
            . " (SELECT COUNT(*) FROM loss_print_job_line l WHERE l.job_id = j.id AND l.status = 'failed') AS failed,"
            . " (SELECT COUNT(*) FROM loss_print_job_line l WHERE l.job_id = j.id AND l.status = 'waiting') AS waiting"
            . ' FROM loss_print_job j JOIN loss_device d ON d.id = j.device_id JOIN loss_box b ON b.id = j.box_id WHERE j.id = ? AND j.exercise = CAST(? AS boolean)', [$id, $exercise ? 'true' : 'false']) ?: null;
    }

    /** @return list<array<string, mixed>> les lignes d'un travail, dans l'ordre, avec le numéro et la variante de leur pièce */
    public function linesOf(int $jobId): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT l.id, l.position, l.piece_id, l.identifier, l.status, l.reason, l.tag_id, l.done_at, p.number, p.variant_id, p.status AS piece_status'
            . ' FROM loss_print_job_line l JOIN loss_piece p ON p.id = l.piece_id WHERE l.job_id = ? ORDER BY l.position', [$jobId]);
    }

    /** Annule les lignes qui attendent encore ; rend leur nombre */
    public function cancelWaiting(int $jobId, string $now): int
    {
        return (int) $this->db()->executeStatement('UPDATE loss_print_job_line SET status = ?, done_at = ? WHERE job_id = ? AND status = ?', [LossPrintJobLine::CANCELLED, $now, $jobId, LossPrintJobLine::WAITING]);
    }

    public function jobCancelled(int $jobId, string $now): void
    {
        $this->db()->executeStatement('UPDATE loss_print_job SET status = ?, cancelled_at = ? WHERE id = ?', [LossPrintJob::CANCELLED, $now, $jobId]);
    }

    /**
     * L'état d'un travail suit ses lignes : en cours dès qu'une ligne est close, fini quand plus aucune n'attend. Un travail annulé
     * ou fini ne bouge plus.
     */
    public function refreshJob(int $jobId, string $now): void
    {
        $this->db()->executeStatement(
            "UPDATE loss_print_job j SET status = CASE WHEN EXISTS (SELECT 1 FROM loss_print_job_line l WHERE l.job_id = j.id AND l.status = 'waiting') THEN 'running' ELSE 'done' END,"
            . " finished_at = CASE WHEN EXISTS (SELECT 1 FROM loss_print_job_line l WHERE l.job_id = j.id AND l.status = 'waiting') THEN NULL ELSE CAST(:now AS timestamp) END"
            . ' WHERE j.id = :id AND j.status IN (:open)', ['now' => $now, 'id' => $jobId, 'open' => LossPrintJob::OPEN], ['open' => ArrayParameterType::STRING]);
    }

    // ----- Le boîtier -----

    /**
     * Les lignes qu'un vrai boîtier peut imprimer maintenant : celles qui attendent, dans ses travaux ouverts, sur ses imprimantes
     * en service, dont le mot de passe se relit (`$ranks`), dont la pièce est en service et a un code-barres à imprimer (celui de
     * sa variante, ou celui de l'article quand il n'a qu'une variante : la règle de `LossPieceService::catalogue`). Une ligne qui
     * ne peut pas être servie n'occupe pas la file. Le travail le plus ancien d'abord.
     *
     * @param list<int> $ranks rangs des mots de passe relisibles
     *
     * @return list<array<string, mixed>>
     */
    public function waitingLinesOfBox(int $boxId, array $ranks, int $limit): array
    {
        if ($ranks === []) {
            return [];
        }

        return $this->db()->fetchAllAssociative(
            'SELECT l.id, l.job_id, l.position, l.piece_id, l.identifier, j.with_price, j.secret_rank, d.serial, p.number, p.variant_id'
            . ' FROM loss_print_job_line l JOIN loss_print_job j ON j.id = l.job_id JOIN loss_device d ON d.id = j.device_id JOIN loss_piece p ON p.id = l.piece_id'
            . ' JOIN product_variant v ON v.id = p.variant_id JOIN product a ON a.id = v.product_id'
            . ' WHERE j.box_id = :box AND NOT j.exercise AND j.status IN (:open) AND l.status = :waiting AND d.status <> :retired AND j.secret_rank IN (:ranks) AND p.status <> :gone'
            . ' AND (v.barcode IS NOT NULL OR (a.barcode IS NOT NULL AND (SELECT COUNT(*) FROM product_variant x WHERE x.product_id = v.product_id) = 1))'
            . ' ORDER BY j.id, l.position LIMIT ' . max(1, $limit),
            ['box' => $boxId, 'open' => LossPrintJob::OPEN, 'waiting' => LossPrintJobLine::WAITING, 'retired' => LossDevice::RETIRED, 'ranks' => $ranks, 'gone' => LossPiece::RETIRED],
            ['open' => ArrayParameterType::STRING, 'ranks' => ArrayParameterType::INTEGER]);
    }

    /**
     * La ligne d'un vrai travail de CE boîtier et de CETTE imprimante, sans verrou : de quoi savoir quoi tenir, dans l'ordre commun
     * à tout le module (la pièce, puis le travail, puis la ligne).
     *
     * @return array{job_id: int, piece_id: int}|null
     */
    public function lineRef(int $lineId, int $boxId, int $deviceId): ?array
    {
        $row = $this->db()->fetchAssociative('SELECT l.job_id, l.piece_id FROM loss_print_job_line l JOIN loss_print_job j ON j.id = l.job_id WHERE l.id = ? AND j.box_id = ? AND j.device_id = ? AND NOT j.exercise',
            [$lineId, $boxId, $deviceId]);

        return $row === false ? null : ['job_id' => (int) $row['job_id'], 'piece_id' => (int) $row['piece_id']];
    }

    /**
     * Tient d'avance tout ce que les résultats d'un lot vont toucher, dans l'ordre commun au module : les pièces par identifiant
     * croissant, puis les travaux par identifiant croissant. Un lot ne tient donc jamais un travail en demandant une pièce.
     *
     * @param list<int> $pieceIds
     * @param list<int> $jobIds
     */
    public function lockForResults(array $pieceIds, array $jobIds): void
    {
        if ($pieceIds !== []) {
            $this->db()->fetchFirstColumn('SELECT id FROM loss_piece WHERE id IN (?) ORDER BY id FOR UPDATE', [$pieceIds], [ArrayParameterType::INTEGER]);
        }
        if ($jobIds !== []) {
            $this->db()->fetchFirstColumn('SELECT id FROM loss_print_job WHERE id IN (?) ORDER BY id FOR UPDATE', [$jobIds], [ArrayParameterType::INTEGER]);
        }
    }

    /** Tient un travail jusqu'à la fin de la transaction (l'annulation le tient aussi : un résultat et une annulation ne se croisent pas) */
    public function lockJob(int $jobId): void
    {
        $this->db()->fetchOne('SELECT id FROM loss_print_job WHERE id = ? FOR UPDATE', [$jobId]);
    }

    /** @return array<string, mixed>|null la ligne, tenue */
    public function lockedLine(int $lineId): ?array
    {
        return $this->db()->fetchAssociative('SELECT id, job_id, piece_id, identifier, status, reason, tag_id FROM loss_print_job_line WHERE id = ? FOR UPDATE', [$lineId]) ?: null;
    }

    /**
     * Une pièce est retirée : ses lignes qui attendent encore se closent en échec (rien ne sera imprimé pour elle), et leurs
     * travaux suivent. L'appelant tient la pièce ; les travaux sont tenus avant leurs lignes.
     *
     * @return int nombre de lignes closes
     */
    public function failWaitingOfPiece(int $pieceId, string $reason, string $now): int
    {
        $jobs = array_map('intval', $this->db()->fetchFirstColumn('SELECT DISTINCT job_id FROM loss_print_job_line WHERE piece_id = ? AND status = ? ORDER BY job_id', [$pieceId, LossPrintJobLine::WAITING]));
        $closed = 0;
        foreach ($jobs as $jobId) {
            $this->lockJob($jobId);
            $closed += (int) $this->db()->executeStatement('UPDATE loss_print_job_line SET status = ?, reason = ?, done_at = ? WHERE job_id = ? AND piece_id = ? AND status = ?',
                [LossPrintJobLine::FAILED, $reason, $now, $jobId, $pieceId, LossPrintJobLine::WAITING]);
            $this->refreshJob($jobId, $now);
        }

        return $closed;
    }

    public function lineWritten(int $lineId, int $tagId, string $now): void
    {
        $this->db()->executeStatement('UPDATE loss_print_job_line SET status = ?, tag_id = ?, done_at = ? WHERE id = ?', [LossPrintJobLine::WRITTEN, $tagId, $now, $lineId]);
    }

    public function lineFailed(int $lineId, string $reason, string $now): void
    {
        $this->db()->executeStatement('UPDATE loss_print_job_line SET status = ?, reason = ?, done_at = ? WHERE id = ?', [LossPrintJobLine::FAILED, $reason, $now, $lineId]);
    }

    /** Nombre de puces écrites et relues par une imprimante, dans ce monde */
    public function writtenCount(bool $exercise): int
    {
        return (int) $this->db()->fetchOne("SELECT COUNT(*) FROM loss_print_job_line l JOIN loss_print_job j ON j.id = l.job_id WHERE l.status = 'written' AND j.exercise = CAST(? AS boolean)", [$exercise ? 'true' : 'false']);
    }

    // ----- Mode exercice -----

    /** @return list<array<string, mixed>> des pièces d'exercice en service qui ne portent aucune puce et n'attendent dans aucun travail */
    public function exercisePiecesWithoutTag(int $limit): array
    {
        return $this->db()->fetchAllAssociative(
            "SELECT p.id, p.number, p.variant_id FROM loss_piece p WHERE p.exercise AND p.status <> 'retired'"
            . " AND NOT EXISTS (SELECT 1 FROM loss_tag t WHERE t.piece_id = p.id AND t.status = 'associated')"
            . " AND NOT EXISTS (SELECT 1 FROM loss_print_job_line l WHERE l.piece_id = p.id AND l.status = 'waiting') ORDER BY p.id LIMIT " . max(1, $limit));
    }

    /** Efface les travaux d'exercice (leurs lignes partent avec eux) ; rend leur nombre */
    public function deleteExercise(): int
    {
        return (int) $this->db()->executeStatement('DELETE FROM loss_print_job WHERE exercise');
    }

    private function db(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
