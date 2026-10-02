<?php

namespace App\Message;

/** Tâche de fond : préparer une vidéo de la médiathèque pour une scène au défilement (voir ScrollVideoPreparer) */
final class PrepareScrollVideoMessage
{
    public function __construct(
        public readonly int $mediaId,
        public readonly string $tenantCode
    ) {
    }
}
