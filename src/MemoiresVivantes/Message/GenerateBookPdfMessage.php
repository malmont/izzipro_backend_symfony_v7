<?php

namespace App\MemoiresVivantes\Message;

/**
 * Mise en page complète d'un livre (intérieur + couverture) dans la file `pdf` (lot 3, 03/11/2026) : la requête web ne rend plus
 * le PDF elle-même quand le client demande `async=1`. Le message ne porte que des identifiants et des options de mise en page ;
 * le nom d'auteur reste privé pour n'apparaître ni dans les battements de cœur des workers ni dans l'écran /admin/workers
 * (WorkerHeartbeatSubscriber::describe ne lit que les propriétés publiques d'une liste fermée).
 */
final class GenerateBookPdfMessage
{
    public function __construct(
        public readonly string $bookId,
        public readonly string $tenantCode,
        public readonly string $coverStyle = 'biographic_split',
        public readonly ?string $bgColor = null,
        public readonly ?string $font = null,
        private readonly ?string $authorName = null,
    ) {
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }
}
