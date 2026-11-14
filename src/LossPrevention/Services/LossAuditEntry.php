<?php

namespace App\LossPrevention\Services;

/**
 * Lecture d'une entrée du journal de caisse (`pos_audit_log`) par le module : colonnes, plus le sujet et les données décodés du JSON du
 * contenu. Les clés de `data` sont « présentes ou absentes », jamais obligatoires (des entrées anciennes ne les ont pas toutes). Le contenu
 * brut n'est JAMAIS recopié dans les tables du module : il porte le courriel du caissier.
 */
final class LossAuditEntry
{
    public const COLUMNS = 'id, sequence, type, occurred_at, user_id, cash_register_id, session_id, terminal_id, hash, payload';

    /**
     * @param array<string, mixed> $row ligne de `pos_audit_log` (colonnes `COLUMNS`)
     * @return array{id: int, sequence: int, type: string, occurredAt: \DateTimeImmutable, userId: ?int, cashRegisterId: ?int, sessionId: ?int, terminalId: ?int, hash: string, subject: ?array{type: string, id: ?int}, data: array<string, mixed>}
     */
    public static function fromRow(array $row): array
    {
        $payload = json_decode((string) $row['payload'], true);
        $subject = is_array($payload) && is_array($payload['subject'] ?? null) && isset($payload['subject']['type'])
            ? ['type' => (string) $payload['subject']['type'], 'id' => isset($payload['subject']['id']) ? (int) $payload['subject']['id'] : null] : null;

        return [
            'id' => (int) $row['id'], 'sequence' => (int) $row['sequence'], 'type' => (string) $row['type'], 'occurredAt' => self::utc((string) $row['occurred_at']),
            'userId' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'cashRegisterId' => $row['cash_register_id'] !== null ? (int) $row['cash_register_id'] : null,
            'sessionId' => $row['session_id'] !== null ? (int) $row['session_id'] : null,
            'terminalId' => ($row['terminal_id'] ?? null) !== null ? (int) $row['terminal_id'] : null,
            'hash' => (string) $row['hash'], 'subject' => $subject,
            'data' => is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : [],
        ];
    }

    /**
     * Entrées d'un type dans une session de caisse, jusqu'à une séquence, dans l'ordre. Le journal de caisse ne se purge jamais et n'a d'index
     * que sur la séquence, le type et (poste, heure) : la lecture est donc bornée par le poste et l'heure d'ouverture de la session, pour
     * passer par cet index au lieu de relire tout le type depuis l'origine. Aucun index n'est ajouté à cette table de caisse.
     *
     * @return list<array<string, mixed>> entrées décodées (`fromRow`)
     */
    public static function ofSession(\Doctrine\DBAL\Connection $conn, string $type, int $sessionId, ?int $cashRegisterId, int $untilSequence, int $floorSequence = 0): array
    {
        // `$floorSequence` : plancher de l'analyse (rien de ce qui précède la notice aux salariés n'est relu, même dans une session en cours)
        $sql = 'SELECT ' . self::COLUMNS . ' FROM pos_audit_log WHERE type = ? AND session_id = ? AND sequence <= ? AND sequence > ?';
        $params = [$type, $sessionId, $untilSequence, $floorSequence];
        $opened = $conn->fetchOne('SELECT created_at FROM caisse WHERE id = ?', [$sessionId]);
        if ($cashRegisterId !== null && is_string($opened) && $opened !== '') {
            $sql .= ' AND cash_register_id = ? AND occurred_at >= ?';
            $params[] = $cashRegisterId;
            $params[] = self::utc($opened)->modify('-1 minute')->format('Y-m-d H:i:s'); // marge : l'heure d'ouverture et celle du journal sont prises à des instants voisins
        }

        return array_map([self::class, 'fromRow'], $conn->fetchAllAssociative($sql . ' ORDER BY sequence', $params));
    }

    /** Les dates de la base sont des instants UTC sans fuseau */
    public static function utc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public static function iso(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    /** Identifiant du responsable qui a validé l'action (C3), s'il y en a un */
    public static function approvedById(array $data): ?int
    {
        return is_array($data['approvedBy'] ?? null) && isset($data['approvedBy']['id']) ? (int) $data['approvedBy']['id'] : null;
    }

    public static function intOrNull(mixed $v): ?int
    {
        return is_numeric($v) ? (int) round((float) $v) : null;
    }
}
