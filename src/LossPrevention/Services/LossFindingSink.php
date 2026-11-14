<?php

namespace App\LossPrevention\Services;

/**
 * Ce à quoi une règle confie ses constats : l'écrivain en base (`LossFindingWriter`, l'analyse) ou un puits en mémoire (`LossPreviewSink`,
 * l'aperçu d'un réglage, qui n'écrit rien). Une règle ne sait pas lequel elle a en face d'elle : l'aperçu joue donc les vraies règles.
 */
interface LossFindingSink
{
    /**
     * @param array<string, mixed> $finding colonnes du constat (clé `dedup_key`, `facts`, `on_duty`…)
     * @param list<array<string, mixed>> $evidence
     * @return int identifiant du constat créé ou complété
     */
    public function record(array $finding, array $evidence): int;

    /**
     * @param array<string, mixed> $existing constat rendu par `latestForPerson`
     * @param array<string, mixed> $facts
     * @param list<array{id: int, role: string}> $onDuty
     * @param list<array<string, mixed>> $evidence
     */
    public function complete(array $existing, array $facts, array $onDuty, ?int $amountAtStake, \DateTimeImmutable $occurredTo, array $evidence, bool $recent = false): void;

    /** @return array<string, mixed>|null */
    public function latestForPerson(string $ruleCode, int $userId, \DateTimeImmutable $since, \DateTimeImmutable $until): ?array;
}
