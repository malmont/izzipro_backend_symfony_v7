<?php

namespace App\LossPrevention\Services\Rule;

use App\LossPrevention\Services\LossAnalysisContext;

/**
 * Une règle de l'analyse : elle lit des entrées du journal de caisse (types qu'elle déclare), relit les montants dans les tables sources et
 * confie ses constats à l'écrivain. Elle ne modifie jamais la caisse, et ne recopie jamais le texte du journal (il porte le courriel du
 * caissier) : des identifiants, des nombres, des dates, des motifs saisis.
 */
interface LossRuleInterface
{
    public function code(): string;

    /** @return list<string> types du journal de caisse à lui présenter */
    public function types(): array;

    /**
     * @param array{id: int, sequence: int, type: string, occurredAt: \DateTimeImmutable, userId: ?int, cashRegisterId: ?int, sessionId: ?int, hash: string, subject: ?array{type: string, id: ?int}, data: array<string, mixed>} $entry
     */
    public function handle(array $entry, LossAnalysisContext $ctx): void;
}
