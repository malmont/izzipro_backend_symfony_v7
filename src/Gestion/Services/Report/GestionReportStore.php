<?php

namespace App\Gestion\Services\Report;

use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;

/** Lot DOC-1 : les rapports PDF du site courant (table `gestion_report`), lus et écrits en SQL sur la connexion du site */
class GestionReportStore
{
    public const PENDING = 'pending';
    public const DONE = 'done';
    public const FAILED = 'failed';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /**
     * @param array<string, mixed>      $params
     * @param array<string, mixed>|null $content les chiffres calculés à la demande (bilans, lot DOC-2) ; null pour une réponse de la console
     * @param array<string, mixed>|null $advice  l'avis de la console demandé avec le bilan : `{ messageId, plain }`, puis son issue
     */
    public function create(string $kind, array $params, string $title, string $locale, string $fingerprint, ?string $by, \DateTimeImmutable $expiresAt, ?array $content = null, ?array $advice = null): int
    {
        $json = static fn (?array $v): ?string => $v === null ? null : json_encode($v, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        return (int) $this->conn()->fetchOne(
            'INSERT INTO gestion_report (kind, params, state, title, locale, fingerprint, created_by, expires_at, content, advice) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$kind, $json($params), self::PENDING, mb_substr($title, 0, 255), $locale, $fingerprint, $by !== null ? mb_substr($by, 0, 180) : null, $expiresAt->format('Y-m-d H:i:s'), $json($content), $json($advice)]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->conn()->fetchAssociative('SELECT * FROM gestion_report WHERE id = ?', [$id]);

        return $row === false ? null : self::decode($row);
    }

    /** Un rapport que l'application peut lire : ni effacé, ni passé son délai de conservation */
    public function readable(int $id): ?array
    {
        $row = $this->conn()->fetchAssociative('SELECT * FROM gestion_report WHERE id = ? AND deleted_at IS NULL AND expires_at > NOW()', [$id]);

        return $row === false ? null : self::decode($row);
    }

    /** Le rapport déjà prêt de la même demande (même empreinte, même langue), non expiré, ou null */
    public function ready(string $fingerprint): ?array
    {
        $row = $this->conn()->fetchAssociative("SELECT * FROM gestion_report WHERE fingerprint = ? AND state = 'done' AND deleted_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1", [$fingerprint]);

        return $row === false ? null : self::decode($row);
    }

    /** Le rapport en préparation de la même demande, ou null : une seconde demande ne le refabrique pas */
    public function pending(string $fingerprint): ?array
    {
        $row = $this->conn()->fetchAssociative("SELECT * FROM gestion_report WHERE fingerprint = ? AND state = 'pending' ORDER BY id DESC LIMIT 1", [$fingerprint]);

        return $row === false ? null : self::decode($row);
    }

    /** Rapports demandés dans la plage, effacés compris : effacer un rapport ne rend pas une place au plafond du jour */
    public function createdBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM gestion_report WHERE created_at >= ? AND created_at < ?', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
    }

    /**
     * Passe un rapport en préparation à « prêt » ; vrai si c'est bien ce pas qui a été fait. Lot DOC-2 : l'issue de l'avis de la console
     * (`advice`), l'unité décomptée, et l'empreinte du bilan SANS avis quand l'avis n'a pas pu être rédigé (la même demande avec avis
     * ne retombe alors pas sur ce fichier : elle redemande l'avis).
     *
     * @param array<string, mixed>|null $advice
     */
    public function done(int $id, string $fileName, int $size, int $pages, ?array $advice = null, int $units = 0, ?string $fingerprint = null): bool
    {
        return $this->conn()->executeStatement(
            "UPDATE gestion_report SET state = 'done', file_name = ?, size = ?, pages = ?, ready_at = NOW(), error_code = NULL, advice = COALESCE(?::jsonb, advice), units_charged = ?, fingerprint = COALESCE(?, fingerprint) WHERE id = ? AND state = 'pending'",
            [$fileName, $size, $pages, $advice === null ? null : json_encode($advice, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), $units, $fingerprint, $id]
        ) === 1;
    }

    /** `units` : l'unité de l'avis de la console déjà décomptée quand le fichier échoue après lui (elle reste notée sur le rapport) */
    public function failed(int $id, string $code, ?int $pages = null, int $units = 0): void
    {
        $this->conn()->executeStatement("UPDATE gestion_report SET state = 'failed', error_code = ?, pages = ?, ready_at = NOW(), units_charged = GREATEST(units_charged, ?) WHERE id = ? AND state = 'pending'", [mb_substr($code, 0, 40), $pages, $units, $id]);
    }

    /** Les rapports restés en préparation au-delà du délai : tenus pour interrompus */
    public function failStale(int $seconds, string $code): void
    {
        $this->conn()->executeStatement("UPDATE gestion_report SET state = 'failed', error_code = ?, ready_at = NOW() WHERE state = 'pending' AND created_at < NOW() - make_interval(secs => ?)", [$code, $seconds], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER]);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function page(int $page, int $perPage, ?string $kind): array
    {
        $where = ' WHERE deleted_at IS NULL AND expires_at > NOW()' . ($kind !== null ? ' AND kind = ?' : '');
        $args = $kind !== null ? [$kind] : [];
        $total = (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM gestion_report' . $where, $args);
        $rows = $this->conn()->fetchAllAssociative('SELECT * FROM gestion_report' . $where . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . max(0, ($page - 1) * $perPage), $args);

        return [array_map(self::decode(...), $rows), $total];
    }

    /** Efface un rapport pour l'application : la ligne reste (plafond du jour) jusqu'à la fin de sa conservation, sans fichier */
    public function markDeleted(int $id): void
    {
        $this->conn()->executeStatement('UPDATE gestion_report SET deleted_at = NOW(), file_name = NULL WHERE id = ?', [$id]);
    }

    /** Retire la ligne (purge à la fin de la conservation) */
    public function delete(int $id): void
    {
        $this->conn()->executeStatement('DELETE FROM gestion_report WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> les rapports dont le délai de conservation est passé */
    public function expired(): array
    {
        return array_map(self::decode(...), $this->conn()->fetchAllAssociative('SELECT * FROM gestion_report WHERE expires_at <= NOW() ORDER BY id LIMIT 200'));
    }

    /** @param array<string, mixed> $row */
    private static function decode(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['params'] = is_string($row['params']) ? (json_decode($row['params'], true) ?: []) : [];
        foreach (['content', 'advice'] as $json) {
            $row[$json] = is_string($row[$json] ?? null) ? (json_decode($row[$json], true) ?: null) : null;
        }
        foreach (['size', 'pages', 'units_charged'] as $int) {
            $row[$int] = $row[$int] === null ? null : (int) $row[$int];
        }

        return $row;
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
