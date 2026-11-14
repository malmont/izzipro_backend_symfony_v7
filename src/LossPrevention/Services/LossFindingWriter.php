<?php

namespace App\LossPrevention\Services;

use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Entity\LossJournalEntry;
use App\Services\TenantEntityManagerProvider;

/**
 * Écrit les constats et leurs preuves pour l'analyse (`docs/prevention-pertes.md` § 5 et 6). Sans doublon : un constat par clé (règle + sujet +
 * fenêtre), une preuve par (constat, source). Un constat déjà là est complété ; son état et sa décision ne changent jamais ici : un constat
 * décidé n'est pas rouvert, la date de sa dernière preuve dit qu'il y a du nouveau depuis la décision.
 */
class LossFindingWriter implements LossFindingSink
{
    private int $opened = 0;
    private int $completed = 0;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /**
     * @param array<string, mixed> $finding colonnes de `LossFindingRepository::create`
     * @param list<array{kind: string, at: \DateTimeImmutable, refType: string, refId: int, snapshot: array<string, mixed>, sequence?: ?int, hash?: ?string}> $evidence
     * @return int identifiant du constat (créé ou complété)
     */
    public function record(array $finding, array $evidence): int
    {
        $existing = $this->findings()->byKey((string) $finding['dedup_key']);
        if ($existing === null) {
            $finding['on_duty'] = self::mergeOnDuty([], $finding['on_duty']);
            $id = $this->findings()->create($finding);
            $this->addEvidence($id, $evidence);
            $this->journal()->append(LossJournalEntry::FINDING_OPENED, null, $id, ['rule' => $finding['rule_code'], 'variant' => $finding['variant'], 'evidence' => count($evidence)]);
            ++$this->opened;

            return $id;
        }
        $this->complete($existing, $finding['facts'], $finding['on_duty'], $finding['amount_at_stake'], new \DateTimeImmutable((string) $finding['occurred_to']), $evidence, !$finding['backfill']);

        return (int) $existing['id'];
    }

    /**
     * Complète un constat existant (clé déjà connue, ou constat « par personne » dont la fenêtre court encore).
     *
     * @param array<string, mixed> $existing ligne du constat
     * @param array<string, mixed> $facts
     * @param list<array{id: int, role: string}> $onDuty
     * @param list<array<string, mixed>> $evidence
     * @param bool $recent le fait qui complète est postérieur à la première analyse : un constat de reprise cesse d'en être une
     */
    public function complete(array $existing, array $facts, array $onDuty, ?int $amountAtStake, \DateTimeImmutable $occurredTo, array $evidence, bool $recent = false): void
    {
        $added = $this->addEvidence((int) $existing['id'], $evidence);
        if ($added === 0) {
            return; // analyse rejouée : rien de nouveau
        }
        $merged = self::mergeOnDuty(json_decode((string) $existing['on_duty'], true) ?: [], $onDuty);
        $this->findings()->complete((int) $existing['id'], $facts, $merged, $amountAtStake, $occurredTo, true, $recent);
        $this->journal()->append(LossJournalEntry::FINDING_COMPLETED, null, (int) $existing['id'], ['evidence' => $added, 'afterDecision' => $existing['decided_at'] !== null]);
        ++$this->completed;
    }

    /** Constat « par personne » d'une règle dont la fenêtre court encore pour ce compte @return array<string, mixed>|null */
    public function latestForPerson(string $ruleCode, int $userId, \DateTimeImmutable $since, \DateTimeImmutable $until): ?array
    {
        return $this->findings()->latestForPerson($ruleCode, $userId, $since, $until);
    }

    /** @return array{opened: int, completed: int} compteurs en cours */
    public function counters(): array
    {
        return ['opened' => $this->opened, 'completed' => $this->completed];
    }

    /** Remet les compteurs à un état noté avant une règle dont les écritures viennent d'être annulées @param array{opened: int, completed: int} $counters */
    public function restoreCounters(array $counters): void
    {
        $this->opened = $counters['opened'];
        $this->completed = $counters['completed'];
    }

    /** @return array{opened: int, completed: int} compteurs depuis le dernier appel, remis à zéro */
    public function flushCounters(): array
    {
        $out = ['opened' => $this->opened, 'completed' => $this->completed];
        $this->opened = $this->completed = 0;

        return $out;
    }

    /**
     * @param list<array{id: int, role: string}> $a
     * @param list<array{id: int, role: string}> $b
     * @return list<array{id: int, role: string}>
     */
    public static function mergeOnDuty(array $a, array $b): array
    {
        $seen = [];
        foreach (array_merge($a, $b) as $p) {
            if (isset($p['id'], $p['role'])) {
                $seen[(int) $p['id'] . ':' . $p['role']] = ['id' => (int) $p['id'], 'role' => (string) $p['role']];
            }
        }

        return array_values($seen);
    }

    /** @param list<array<string, mixed>> $evidence */
    private function addEvidence(int $findingId, array $evidence): int
    {
        $added = 0;
        $repo = $this->emProvider->getEntityManager()->getRepository(LossEvidence::class);
        foreach ($evidence as $e) {
            $added += $repo->add($findingId, $e['kind'], $e['at'], $e['refType'], (int) $e['refId'], $e['snapshot'], $e['sequence'] ?? null, $e['hash'] ?? null) ? 1 : 0;
        }

        return $added;
    }

    private function findings(): \App\LossPrevention\Repository\LossFindingRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossFinding::class);
    }

    private function journal(): \App\LossPrevention\Repository\LossJournalRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossJournalEntry::class);
    }
}
