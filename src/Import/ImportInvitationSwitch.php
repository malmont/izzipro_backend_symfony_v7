<?php

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lot IMP-2 : l'interrupteur de l'envoi des invitations aux clients repris d'une ancienne boutique. ÉTEINT dans le code livré
 * (`app.import.invitations_open: false`, `config/services.yaml`) : tant que la page du lien n'existe pas sur la boutique publique et
 * que le propriétaire n'a pas validé le texte du courriel, AUCUNE invitation ne peut partir, ni par la route, ni par le worker.
 * L'ouvrir est une ligne de configuration, à livrer ce jour-là. Tout le reste de l'import des clients marche interrupteur éteint.
 */
class ImportInvitationSwitch
{
    public function __construct(#[Autowire('%app.import.invitations_open%')] private readonly bool $open)
    {
    }

    public function isOpen(): bool
    {
        return $this->open;
    }
}
