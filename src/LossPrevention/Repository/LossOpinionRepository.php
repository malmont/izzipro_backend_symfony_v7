<?php

namespace App\LossPrevention\Repository;

use App\LossPrevention\Entity\LossOpinion;
use Doctrine\ORM\EntityRepository;

/**
 * Avis de l'IA sur les constats (lot L5-c, `docs/prevention-pertes.md` § 18.10) : un avis courant par constat.
 *
 * @extends EntityRepository<LossOpinion>
 */
class LossOpinionRepository extends EntityRepository
{
    /** @return array<string, mixed>|null */
    public function row(int $findingId): ?array
    {
        return $this->getEntityManager()->getConnection()->fetchAssociative('SELECT * FROM loss_opinion WHERE finding_id = ?', [$findingId]) ?: null;
    }

    /**
     * Un avis demandé : il REMPLACE l'avis courant (« Actualiser »), sauf s'il en est déjà un en préparation. Une seule instruction : de deux
     * demandes simultanées, une seule l'obtient. Rend vrai si la demande est posée.
     *
     * @param array<string, int> $measures
     */
    public function begin(int $findingId, bool $exercise, ?int $percent, array $measures, ?int $userId, ?int $usageId, ?string $evidenceAt): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'INSERT INTO loss_opinion (finding_id, exercise, state, percent, measures, content, error, example, requested_by, requested_at, generated_at, evidence_at, credits_used, usage_id, attempt)'
            . " VALUES (:finding, :exercise, 'pending', :percent, :measures, NULL, NULL, false, :user, :now, NULL, :evidence, 0, :usage, 1)"
            // chaque demande porte un numéro : un passage du worker n'écrit que sur SA demande (relecture du 17/11/2026)
            . " ON CONFLICT (finding_id) DO UPDATE SET attempt = loss_opinion.attempt + 1, state = 'pending', percent = EXCLUDED.percent, measures = EXCLUDED.measures, content = NULL, error = NULL, example = false,"
            . ' requested_by = EXCLUDED.requested_by, requested_at = EXCLUDED.requested_at, generated_at = NULL, evidence_at = EXCLUDED.evidence_at, credits_used = 0, usage_id = EXCLUDED.usage_id'
            . " WHERE loss_opinion.state <> 'pending' RETURNING true",
            ['finding' => $findingId, 'exercise' => $exercise ? 'true' : 'false', 'percent' => $percent, 'measures' => json_encode((object) $measures), 'user' => $userId,
                'now' => gmdate('Y-m-d H:i:s'), 'evidence' => $evidenceAt, 'usage' => $usageId]
        );
    }

    /**
     * L'avis rédigé (ou l'exemple de l'exercice) ; seulement s'il est encore en préparation : une réponse tardive n'écrase pas un échec.
     *
     * @param array<string, mixed> $content
     */
    public function finish(int $findingId, array $content, int $credits, bool $example = false, ?int $attempt = null): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE loss_opinion SET state = 'done', content = ?, generated_at = ?, credits_used = ?, example = ? WHERE finding_id = ? AND state = 'pending'"
            . ($attempt !== null ? ' AND attempt = ?' : ''),
            [json_encode($content, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s'), $credits, $example ? 'true' : 'false', $findingId, ...($attempt !== null ? [$attempt] : [])]) > 0;
    }

    /** L'avis n'a pas pu être rédigé (`$error` : un code) ; seulement s'il est encore en préparation, et pour cette demande-là si `$attempt` */
    public function fail(int $findingId, string $error, ?int $attempt = null): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE loss_opinion SET state = 'failed', error = ?, generated_at = ? WHERE finding_id = ? AND state = 'pending'" . ($attempt !== null ? ' AND attempt = ?' : ''),
            [$error, gmdate('Y-m-d H:i:s'), $findingId, ...($attempt !== null ? [$attempt] : [])]) > 0;
    }

    /**
     * Les avis en préparation depuis avant `$before` : rendus en échec par l'appelant (délai dépassé), avec leur réservation d'unité.
     *
     * @return list<array{finding_id: int, usage_id: ?int, attempt: int}>
     */
    public function staleBefore(string $before): array
    {
        return array_map(static fn (array $r): array => ['finding_id' => (int) $r['finding_id'], 'usage_id' => $r['usage_id'] !== null ? (int) $r['usage_id'] : null, 'attempt' => (int) $r['attempt']],
            $this->getEntityManager()->getConnection()->fetchAllAssociative("SELECT finding_id, usage_id, attempt FROM loss_opinion WHERE state = 'pending' AND requested_at < ?", [$before]));
    }

    /**
     * Les constats SEMBLABLES d'un constat, comptés par le serveur : même règle et même variante, sur ce site, depuis `$since`, sans le
     * constat lui-même ni l'exercice. `decided` : tranchés (confirmés, expliqués, classés sans suite) ; `confirmed` : confirmés.
     *
     * @return array{similar: int, decided: int, confirmed: int}
     */
    public function similar(string $rule, string $variant, int $findingId, string $since): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            "SELECT COUNT(*) AS similar, COUNT(*) FILTER (WHERE status IN ('confirmed', 'explained', 'dismissed')) AS decided, COUNT(*) FILTER (WHERE status = 'confirmed') AS confirmed"
            . ' FROM loss_finding WHERE rule_code = ? AND variant = ? AND id <> ? AND exercise = false AND created_at >= ?', [$rule, $variant, $findingId, $since]) ?: [];

        return ['similar' => (int) ($row['similar'] ?? 0), 'decided' => (int) ($row['decided'] ?? 0), 'confirmed' => (int) ($row['confirmed'] ?? 0)];
    }
}
