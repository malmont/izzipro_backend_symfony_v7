<?php

namespace App\Gestion\UseCase;

use App\Gestion\Lexique\ScreenGuides;
use App\Gestion\Services\GestionConflictException;

/** Guide d'un écran de la gestion (lot W2-APPRENDRE A-4, `docs/lexique-gestion.md` § 6) : sans IA, écrit par le serveur */
class GestionScreenGuideUseCase
{
    public function __construct(private readonly ScreenGuides $guides)
    {
    }

    /** @return array<string, mixed> 404 `SCREEN_GUIDE_NOT_FOUND` pour un code d'écran inconnu */
    public function guide(string $code, string $locale): array
    {
        return $this->guides->guide($code, $locale) ?? throw new GestionConflictException('SCREEN_GUIDE_NOT_FOUND', 'Écran inconnu : aucun guide.', 404, ['screen' => $code]);
    }
}
