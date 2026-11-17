<?php

namespace App\LossPrevention\Message;

/** Rédiger l'avis de l'IA sur un constat (lot L5-c, `docs/prevention-pertes.md` § 18.10) : file `gestion_ai` */
final class WriteLossOpinionMessage
{
    public function __construct(public readonly int $findingId, public readonly string $tenantCode, public readonly string $locale = 'fr')
    {
    }
}
