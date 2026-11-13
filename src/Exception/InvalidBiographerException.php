<?php

namespace App\Exception;

/** Rendez-vous : `biographer_id` ne désigne pas un membre de l'équipe Mémoires Vivantes (13/11/2026) → 400 BIOGRAPHER_INVALID */
final class InvalidBiographerException extends \DomainException
{
    public const CODE = 'BIOGRAPHER_INVALID';

    public function __construct(int $biographerId)
    {
        parent::__construct(sprintf('Le biographe %d n\'existe pas ou ne fait pas partie de l\'équipe Mémoires Vivantes.', $biographerId));
    }
}
