<?php

namespace App\Gestion\Services\Conversation\Action;

/**
 * Une action que le fil de la gestion sait préparer (lot IA-ACT, 13/11/2026, `docs/conversation-gestion.md` § 9) : ses paramètres sous
 * forme de questions, son aperçu, sa confirmation par le service existant, ce qui bloque son annulation et son annulation. Les réponses
 * du fil (identifiant de question => valeur) sont ses paramètres. La clé d'une action est celle du guide des séquences.
 */
interface GestionAction
{
    /** Clé de l'action (`expense.create`…), unique, la même que dans le guide des séquences */
    public static function key(): string;

    /**
     * Questions de l'action, une par paramètre, répondues ou non, et les refus qui empêchent l'aperçu (lecture seule).
     *
     * @param array<string, mixed> $answers
     *
     * @return array{questions: list<array<string, mixed>>, issues: list<array{path: string, code: string}>}
     */
    public function build(array $answers, string $locale): array;

    /**
     * Contrôle d'une réponse, celui du service existant : rend la valeur à garder (null : réponse retirée) ou lève un refus de réponse.
     *
     * @param array<string, mixed> $question
     * @param array<string, mixed> $answers réponses déjà gardées
     */
    public function accept(array $question, mixed $value, int $index, array $answers): mixed;

    /**
     * Ce que la confirmation écrira, sans rien écrire. L'empreinte de l'aperçu couvre ce tableau.
     *
     * @param array<string, mixed> $answers
     *
     * @return array<string, mixed>
     */
    public function preview(array $answers, string $locale): array;

    /**
     * Confirmation : écrit par le service existant. `result` est rendu à l'application ; `undo` est ce qu'il faut pour annuler et pour
     * constater ce qui a bougé depuis.
     *
     * @param array<string, mixed> $answers
     *
     * @return array{result: array<string, mixed>, undo: array<string, mixed>}
     */
    public function execute(array $answers, ?string $user): array;

    /**
     * Ce qui empêche l'annulation aujourd'hui (lecture seule).
     *
     * @param array<string, mixed> $undo
     *
     * @return list<array{code: string, message: string}>
     */
    public function blockers(array $undo): array;

    /**
     * Annulation : retire ou remet exactement ce que la confirmation a écrit.
     *
     * @param array<string, mixed> $undo
     *
     * @return array<string, mixed> ce qui a été retiré ou remis
     */
    public function undo(array $undo): array;
}
